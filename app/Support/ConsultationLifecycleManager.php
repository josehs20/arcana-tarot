<?php

namespace App\Support;

use App\Enums\AttendantAvailability;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Events\ConsultationRequested;
use App\Events\ConsultationStateChanged;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Models\Wallet;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ciclo de vida da consulta.
 *
 * Toda transição acontece em transação com lock pessimista. O banco também
 * mantém travas únicas para uma consulta aberta por cliente e uma ativa por
 * atendente, de modo que duas requisições simultâneas não consigam furar a
 * regra mesmo em processos distintos.
 */
final class ConsultationLifecycleManager
{
    public function __construct(
        private readonly ConsultationPricing $pricing,
        private readonly ConsultationModalityCatalog $modalities,
        private readonly ConsultationBillingManager $billing,
        private readonly CommissionManager $commissions,
        private readonly ConsultationActivityTracker $activity,
        private readonly ConsultationSafety $safety,
    ) {}

    public function request(
        User $client,
        User $attendant,
        ConsultationModality $modality = ConsultationModality::Text,
    ): Consultation {
        $this->ensureActiveUser($client);
        $this->ensureActiveUser($attendant);

        if (! $client->isClient()) {
            throw new AuthorizationException('Apenas clientes podem solicitar uma consulta.');
        }

        $consultation = $this->commitAndBroadcastState(null, function () use ($client, $attendant, $modality): Consultation {
            $lockedClient = User::query()->lockForUpdate()->find($client->getKey());
            $lockedAttendant = User::query()->lockForUpdate()->find($attendant->getKey());

            if ($lockedClient === null || ! $lockedClient->isClient() || ! $lockedClient->is_active) {
                throw new AuthorizationException('Sua conta não está apta a solicitar consultas.');
            }

            if ($lockedAttendant === null || ! $lockedAttendant->isAttendant() || ! $lockedAttendant->is_active) {
                throw ValidationException::withMessages([
                    'attendant_id' => 'Este atendente não está disponível para novas consultas.',
                ]);
            }

            $profile = AttendantProfile::query()
                ->where('user_id', $lockedAttendant->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null || ! $profile->is_published || $profile->availability !== AttendantAvailability::Online) {
                throw ValidationException::withMessages([
                    'attendant_id' => 'Este atendente não está disponível para novas consultas.',
                ]);
            }

            if (! $profile->offersModality($modality)) {
                throw ValidationException::withMessages([
                    'modality' => 'Este atendente não oferece atendimento por '.$modality->label().'.',
                ]);
            }

            $modalityReason = $this->modalities->unavailableReason($profile, $modality);

            if ($modalityReason !== null) {
                throw ValidationException::withMessages(['modality' => $modalityReason]);
            }

            if ($this->hasOpenClientConsultation($lockedClient->getKey())) {
                throw ValidationException::withMessages([
                    'consultation' => 'Você já possui uma solicitação ou consulta em aberto.',
                ]);
            }

            if ($this->hasActiveAttendantConsultation($lockedAttendant->getKey())) {
                throw ValidationException::withMessages([
                    'attendant_id' => 'Este atendente está em outra consulta no momento.',
                ]);
            }

            $consultation = new Consultation([
                'client_id' => $lockedClient->getKey(),
                'attendant_id' => $lockedAttendant->getKey(),
                'status' => ConsultationStatus::Requested->value,
                'modality' => $modality->value,
            ]);

            $this->persist(
                $consultation,
                'consultation',
                'Você já possui uma solicitação ou consulta em aberto.',
                'Este atendente está em outra consulta no momento.',
            );

            $this->audit($lockedClient->getKey(), 'consultation.requested', $consultation, [
                'status' => ConsultationStatus::Requested->value,
                'attendant_id' => $lockedAttendant->getKey(),
                'modality' => $modality->value,
            ]);

            return $consultation;
        });

        $this->announceRequest($consultation);

        return $consultation;
    }

    public function accept(User $attendant, Consultation $consultation): Consultation
    {
        $this->ensureActiveUser($attendant);

        return $this->commitAndBroadcastState($consultation, function () use ($attendant, $consultation): Consultation {
            $locked = $this->lockConsultation($consultation);

            $this->ensureAssignedAttendant($attendant, $locked);

            if ($locked->status === ConsultationStatus::Active) {
                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Requested) {
                throw ValidationException::withMessages([
                    'consultation' => 'Apenas solicitações pendentes podem ser aceitas.',
                ]);
            }

            $lockedAttendant = User::query()->lockForUpdate()->find($locked->attendant_id);
            $lockedClient = User::query()->lockForUpdate()->find($locked->client_id);

            if ($lockedAttendant === null || ! $lockedAttendant->isAttendant() || ! $lockedAttendant->is_active) {
                throw ValidationException::withMessages([
                    'consultation' => 'O atendente não está mais disponível para esta consulta.',
                ]);
            }

            if ($lockedClient === null || ! $lockedClient->isClient() || ! $lockedClient->is_active) {
                throw ValidationException::withMessages([
                    'consultation' => 'O cliente não está mais disponível para esta consulta.',
                ]);
            }

            $profile = AttendantProfile::query()
                ->where('user_id', $lockedAttendant->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null || ! $profile->is_published || $profile->availability !== AttendantAvailability::Online) {
                throw ValidationException::withMessages([
                    'consultation' => 'Fique online e com o perfil publicado para aceitar a consulta.',
                ]);
            }

            if ($this->hasOpenClientConsultation($lockedClient->getKey(), $locked->getKey())) {
                throw ValidationException::withMessages([
                    'consultation' => 'O cliente já possui outra solicitação ou consulta em aberto.',
                ]);
            }

            if ($this->hasActiveAttendantConsultation($lockedAttendant->getKey(), $locked->getKey())) {
                throw ValidationException::withMessages([
                    'consultation' => 'Você já está em outra consulta ativa.',
                ]);
            }

            $modality = $locked->modality ?? ConsultationModality::Text;

            if (! $profile->offersModality($modality)) {
                throw ValidationException::withMessages([
                    'consultation' => 'Este atendente não oferece mais atendimento por '.$modality->label().'.',
                ]);
            }

            $modalityReason = $this->modalities->unavailableReason($profile, $modality);

            if ($modalityReason !== null) {
                throw ValidationException::withMessages(['consultation' => $modalityReason]);
            }

            $rate = $this->pricing->rateCreditsPerMinute($modality);
            $unit = $this->pricing->creditUnitPriceCents();
            $minimum = $this->pricing->minimumBalanceCents($modality);

            if ($rate === null || $unit === null || $minimum === null) {
                throw ValidationException::withMessages([
                    'consultation' => 'A tarifa desta modalidade ainda não está configurada.',
                ]);
            }

            $wallet = Wallet::query()
                ->where('user_id', $lockedClient->getKey())
                ->lockForUpdate()
                ->first();

            if ($wallet === null || $wallet->balance_cents < $minimum) {
                throw ValidationException::withMessages([
                    'consultation' => 'O cliente não possui saldo suficiente para iniciar a consulta (mínimo de '.$this->formatCents($minimum).').',
                ]);
            }

            $profile->markSeen();

            $locked->fill([
                'status' => ConsultationStatus::Active->value,
                'commission_rate_bps' => $profile->commission_rate_bps,
                'rate_credits_per_minute' => $rate,
                'credit_unit_price_cents' => $unit,
                'price_per_minute_cents' => $rate * $unit,
                'started_at' => now(),
                'last_activity_at' => now(),
                'client_last_activity_at' => now(),
                'attendant_last_activity_at' => now(),
                'ended_at' => null,
                'ended_reason' => null,
            ]);

            $this->persist(
                $locked,
                'consultation',
                'O cliente já possui outra solicitação ou consulta em aberto.',
                'Você já está em outra consulta ativa.',
            );

            $billing = $this->billing->billDue($locked);

            if ($billing->hasUnpaidMinute()) {
                throw ValidationException::withMessages([
                    'consultation' => 'O cliente não possui saldo suficiente para o primeiro minuto da consulta.',
                ]);
            }

            $locked->refresh();

            $this->audit($lockedAttendant->getKey(), 'consultation.accepted', $locked, [
                'from' => ConsultationStatus::Requested->value,
                'to' => ConsultationStatus::Active->value,
                'modality' => $modality->value,
                'price_per_minute_cents' => $rate * $unit,
                'minimum_balance_cents' => $minimum,
                'commission_rate_bps' => $profile->commission_rate_bps,
                'billed_minutes' => $billing->billedMinutes,
                'charged_cents' => $billing->chargedCents,
            ]);

            return $locked;
        });
    }

    public function reject(User $attendant, Consultation $consultation): Consultation
    {
        $this->ensureActiveUser($attendant);

        return $this->commitAndBroadcastState($consultation, function () use ($attendant, $consultation): Consultation {
            $locked = $this->lockConsultation($consultation);

            $this->ensureAssignedAttendant($attendant, $locked);

            if ($locked->status === ConsultationStatus::Rejected) {
                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Requested) {
                throw ValidationException::withMessages([
                    'consultation' => 'Apenas solicitações pendentes podem ser recusadas.',
                ]);
            }

            $locked->fill([
                'status' => ConsultationStatus::Rejected->value,
                'ended_at' => now(),
                'ended_reason' => ConsultationEndReason::Rejected->value,
            ]);

            $this->persist($locked, 'consultation');

            $this->audit($attendant->getKey(), 'consultation.rejected', $locked, [
                'from' => ConsultationStatus::Requested->value,
                'to' => ConsultationStatus::Rejected->value,
                'reason' => ConsultationEndReason::Rejected->value,
            ]);

            return $locked;
        });
    }

    public function cancel(User $client, Consultation $consultation): Consultation
    {
        $this->ensureActiveUser($client);

        return $this->commitAndBroadcastState($consultation, function () use ($client, $consultation): Consultation {
            $locked = $this->lockConsultation($consultation);

            $this->ensureClient($client, $locked);

            if ($locked->status === ConsultationStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Requested) {
                throw ValidationException::withMessages([
                    'consultation' => 'Uma consulta ativa deve ser encerrada, não cancelada.',
                ]);
            }

            $locked->fill([
                'status' => ConsultationStatus::Cancelled->value,
                'ended_at' => now(),
                'ended_reason' => ConsultationEndReason::Cancelled->value,
            ]);

            $this->persist($locked, 'consultation');

            $this->audit($client->getKey(), 'consultation.cancelled', $locked, [
                'from' => ConsultationStatus::Requested->value,
                'to' => ConsultationStatus::Cancelled->value,
                'reason' => ConsultationEndReason::Cancelled->value,
            ]);

            return $locked;
        });
    }

    /**
     * Encerra uma consulta ativa. Repetir a chamada em estado final é no-op.
     */
    public function end(User $actor, Consultation $consultation): Consultation
    {
        $this->ensureActiveUser($actor);

        return $this->commitAndBroadcastState($consultation, function () use ($actor, $consultation): Consultation {
            $locked = $this->lockConsultation($consultation);

            $this->ensureParticipant($actor, $locked);

            if ($locked->status?->isFinal()) {
                $this->commissions->accrue($locked);

                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Active) {
                throw ValidationException::withMessages([
                    'consultation' => 'Aceite ou recuse a solicitação antes de encerrar.',
                ]);
            }

            $reason = $locked->isAttendant($actor)
                ? ConsultationEndReason::AttendantEnded
                : ConsultationEndReason::ClientEnded;

            if ($locked->isAttendant($actor)) {
                $this->lockProfile($locked->attendant_id)?->markSeen();
            }

            $startedAt = $locked->started_at;

            $locked->fill([
                'status' => ConsultationStatus::Ended->value,
                'ended_at' => now(),
                'ended_reason' => $reason->value,
            ]);

            $this->persist($locked, 'consultation');

            $billing = $this->settleBilling($locked, markOutOfCredits: true);

            if ($billing->hasUnpaidMinute()) {
                $reason = ConsultationEndReason::OutOfCredits;
            }

            $commission = $this->commissions->accrue($locked);

            $this->releaseAttendantPresence($locked->attendant_id);

            $this->activity->closeSessions($locked, $reason->value);

            $this->audit($actor->getKey(), 'consultation.ended', $locked, [
                'from' => ConsultationStatus::Active->value,
                'to' => ConsultationStatus::Ended->value,
                'reason' => $reason->value,
                'duration_seconds' => $startedAt?->diffInSeconds($locked->ended_at) ?? 0,
                'billed_minutes' => $billing->billedMinutes,
                'charged_cents' => $billing->chargedCents,
                'commission_entry_id' => $commission?->getKey(),
                'commission_amount_cents' => $commission?->commission_amount_cents,
            ]);

            return $locked;
        });
    }

    /**
     * Encerramento administrativo/automático (ex.: saldo insuficiente).
     */
    public function endBySystem(Consultation $consultation, ConsultationEndReason $reason): Consultation
    {
        return $this->commitAndBroadcastState($consultation, function () use ($consultation, $reason): Consultation {
            $locked = $this->lockConsultation($consultation);

            if ($locked->status?->isFinal()) {
                $this->commissions->accrue($locked);

                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Active) {
                throw ValidationException::withMessages([
                    'consultation' => 'Apenas consultas ativas podem ser encerradas pelo sistema.',
                ]);
            }

            $locked->fill([
                'status' => ConsultationStatus::Ended->value,
                'ended_at' => now(),
                'ended_reason' => $reason->value,
            ]);

            $this->persist($locked, 'consultation');

            $billing = $this->settleBilling(
                $locked,
                markOutOfCredits: $reason === ConsultationEndReason::OutOfCredits,
            );

            if ($billing->hasUnpaidMinute()) {
                $reason = ConsultationEndReason::OutOfCredits;
            }

            $commission = $this->commissions->accrue($locked);

            $this->releaseAttendantPresence($locked->attendant_id);

            $this->activity->closeSessions($locked, $reason->value);

            $this->audit(null, 'consultation.ended', $locked, [
                'from' => ConsultationStatus::Active->value,
                'to' => ConsultationStatus::Ended->value,
                'reason' => $reason->value,
                'billed_minutes' => $billing->billedMinutes,
                'charged_cents' => $billing->chargedCents,
                'commission_entry_id' => $commission?->getKey(),
                'commission_amount_cents' => $commission?->commission_amount_cents,
            ]);

            return $locked;
        });
    }

    /**
     * Encerra, de forma idempotente, o atendimento ativo do usuário que está
     * saindo da conta. É o mesmo caminho de encerramento usado pelo chat, com
     * motivo auditável distinto para cliente e atendente.
     */
    public function endActiveForLogout(User $user): ?Consultation
    {
        $consultation = Consultation::query()
            ->where('status', ConsultationStatus::Active->value)
            ->where(function ($query) use ($user): void {
                $query->where('client_id', $user->getKey())
                    ->orWhere('attendant_id', $user->getKey());
            })
            ->latest('id')
            ->first();

        if ($consultation === null) {
            return null;
        }

        $reason = $consultation->isAttendant($user)
            ? ConsultationEndReason::AttendantLoggedOut
            : ConsultationEndReason::ClientLoggedOut;

        return $this->endBySystem($consultation, $reason);
    }

    /**
     * Expira uma solicitação pendente. Estados finais são idempotentes.
     */
    public function expire(Consultation $consultation): Consultation
    {
        return $this->commitAndBroadcastState($consultation, function () use ($consultation): Consultation {
            $locked = $this->lockConsultation($consultation);

            if ($locked->status?->isFinal()) {
                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Requested) {
                throw ValidationException::withMessages([
                    'consultation' => 'Apenas solicitações pendentes podem expirar.',
                ]);
            }

            $locked->fill([
                'status' => ConsultationStatus::Expired->value,
                'ended_at' => now(),
                'ended_reason' => ConsultationEndReason::Expired->value,
            ]);

            $this->persist($locked, 'consultation');

            $this->audit(null, 'consultation.expired', $locked, [
                'from' => ConsultationStatus::Requested->value,
                'to' => ConsultationStatus::Expired->value,
                'reason' => ConsultationEndReason::Expired->value,
            ]);

            return $locked;
        });
    }

    public function expireStaleRequests(): int
    {
        $cutoff = now()->subMinutes((int) config('consultation.request_ttl_minutes', 10));

        $ids = Consultation::query()
            ->where('status', ConsultationStatus::Requested->value)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $id) {
            $consultation = Consultation::query()->find($id);

            if ($consultation === null) {
                continue;
            }

            $result = $this->expire($consultation);

            if ($result->status === ConsultationStatus::Expired) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Interrompe uma consulta ativa (desconexão, falha técnica, etc.).
     */
    public function interrupt(Consultation $consultation, ConsultationEndReason $reason = ConsultationEndReason::Disconnected): Consultation
    {
        return $this->commitAndBroadcastState($consultation, function () use ($consultation, $reason): Consultation {
            $locked = $this->lockConsultation($consultation);

            if ($locked->status?->isFinal()) {
                $this->commissions->accrue($locked);

                return $locked;
            }

            if ($locked->status !== ConsultationStatus::Active) {
                throw ValidationException::withMessages([
                    'consultation' => 'Apenas consultas ativas podem ser interrompidas.',
                ]);
            }

            $locked->fill([
                'status' => ConsultationStatus::Interrupted->value,
                'ended_at' => now(),
                'ended_reason' => $reason->value,
            ]);

            $this->persist($locked, 'consultation');

            $billing = $this->settleBilling($locked, markOutOfCredits: false);

            $commission = $this->commissions->accrue($locked);

            $this->releaseAttendantPresence($locked->attendant_id);

            $this->activity->closeSessions($locked, $reason->value);

            $this->audit(null, 'consultation.interrupted', $locked, [
                'from' => ConsultationStatus::Active->value,
                'to' => ConsultationStatus::Interrupted->value,
                'reason' => $reason->value,
                'billed_minutes' => $billing->billedMinutes,
                'charged_cents' => $billing->chargedCents,
                'commission_entry_id' => $commission?->getKey(),
                'commission_amount_cents' => $commission?->commission_amount_cents,
            ]);

            return $locked;
        });
    }

    /**
     * Política de inatividade: consultas ativas sem nenhuma interação (mensagem,
     * carta, digitação ou abertura de tela) por mais tempo que o configurado,
     * somado à tolerância de reconexão, são interrompidas e liquidadas. O
     * batimento de presença não conta como interação.
     */
    public function interruptDisconnectedSessions(): int
    {
        $cutoff = now()->subSeconds($this->safety->interruptionThresholdSeconds());

        $ids = Consultation::query()
            ->where('status', ConsultationStatus::Active->value)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->pluck('id');

        $interrupted = 0;

        foreach ($ids as $id) {
            $consultation = Consultation::query()->find($id);

            if ($consultation === null) {
                continue;
            }

            $result = $this->interrupt($consultation, ConsultationEndReason::Inactivity);

            if ($result->status === ConsultationStatus::Interrupted) {
                $interrupted++;
            }
        }

        return $interrupted;
    }

    /**
     * Confirma a transação antes de anunciar o novo estado. Eventos não são
     * emitidos em no-ops idempotentes. Falhas de fila/broadcast são registradas
     * em log e não revertem a transição já persistida.
     *
     * @param  Closure(): Consultation  $callback
     */
    private function commitAndBroadcastState(?Consultation $consultation, Closure $callback): Consultation
    {
        $previousStatus = $consultation?->status?->value;
        $result = DB::transaction($callback);

        if ($result->status?->isFinal()) {
            $this->closeOpenCall($result);
        }

        if (! $result->wasRecentlyCreated && $previousStatus === $result->status?->value) {
            return $result;
        }

        try {
            ConsultationStateChanged::dispatch(
                (int) $result->getKey(),
                ConsultationStatePresenter::toArray($result),
                [(int) $result->client_id, (int) $result->attendant_id],
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    /**
     * Avisa o atendente escalado no canal privado do usuário.
     *
     * É o único ponto que dispara a notificação instantânea (som + modal de
     * aceite). Falhas de fila/broadcast não desfazem a solicitação já
     * persistida: o painel recupera pendências por HTTP ao reconectar.
     */
    private function announceRequest(Consultation $consultation): void
    {
        try {
            ConsultationRequested::dispatch(
                (int) $consultation->attendant_id,
                ConsultationRequestPresenter::toArray($consultation),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Cobra os minutos vencidos até `ended_at` e reflete o cursor no modelo.
     * Quando o encerramento é do fluxo normal e falta saldo, o motivo final
     * passa a `out_of_credits` na mesma transação.
     */
    private function settleBilling(Consultation $locked, bool $markOutOfCredits): ConsultationBillingResult
    {
        $result = $this->billing->billDue($locked);

        if ($markOutOfCredits && $result->hasUnpaidMinute()) {
            DB::table('consultations')
                ->where('id', $locked->getKey())
                ->update(['ended_reason' => ConsultationEndReason::OutOfCredits->value]);

            $locked->setAttribute('ended_reason', ConsultationEndReason::OutOfCredits->value);
        }

        $locked->refresh();

        return $result;
    }

    /**
     * Fecha a chamada de midia quando a consulta termina por qualquer via
     * (chat, sistema, saldo insuficiente ou desconexao), evitando sessoes
     * WebRTC orfas consumindo creditos sem consulta correspondente.
     */
    private function closeOpenCall(Consultation $consultation): void
    {
        if (! $consultation->requiresCall() || ! $consultation->exists) {
            return;
        }

        DB::table('consultation_calls')
            ->where('consultation_id', $consultation->getKey())
            ->whereIn('status', [
                CallStatus::Ringing->value,
                CallStatus::Accepted->value,
                CallStatus::Connected->value,
            ])
            ->update([
                'status' => CallStatus::Ended->value,
                'ended_reason' => CallEndReason::ConsultationEnded->value,
                'ended_at' => $consultation->ended_at ?? now(),
                'updated_at' => now(),
            ]);
    }

    private function lockConsultation(Consultation $consultation): Consultation
    {
        return Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
    }

    private function ensureActiveUser(User $user): void
    {
        if (! $user->is_active) {
            throw new AuthorizationException('Sua conta está inativa.');
        }
    }

    private function ensureAssignedAttendant(User $user, Consultation $consultation): void
    {
        if (! $user->isAttendant() || ! $consultation->isAttendant($user)) {
            throw new AuthorizationException('Apenas o atendente escalado pode executar esta ação.');
        }
    }

    private function ensureClient(User $user, Consultation $consultation): void
    {
        if (! $user->isClient() || ! $consultation->isClient($user)) {
            throw new AuthorizationException('Apenas o cliente desta consulta pode executar esta ação.');
        }
    }

    private function ensureParticipant(User $user, Consultation $consultation): void
    {
        if (! $consultation->hasParticipant($user)) {
            throw new AuthorizationException('Você não participa desta consulta.');
        }
    }

    private function hasOpenClientConsultation(int $clientId, ?int $ignoreId = null): bool
    {
        return Consultation::query()
            ->where('client_id', $clientId)
            ->whereIn('status', ConsultationStatus::openValues())
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function hasActiveAttendantConsultation(int $attendantId, ?int $ignoreId = null): bool
    {
        return Consultation::query()
            ->where('attendant_id', $attendantId)
            ->where('status', ConsultationStatus::Active->value)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function lockProfile(int $attendantId): ?AttendantProfile
    {
        return AttendantProfile::query()
            ->where('user_id', $attendantId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Mantém online apenas quem deu sinal recente. A ausência de presença
     * recente derruba para offline, sem encerrar cobrança automática.
     */
    private function releaseAttendantPresence(int $attendantId): void
    {
        $profile = $this->lockProfile($attendantId);

        if ($profile === null || $profile->availability !== AttendantAvailability::Online) {
            return;
        }

        if ($profile->hasRecentPresence()) {
            return;
        }

        $profile->availability = AttendantAvailability::Offline;
        $profile->saveQuietly();

        AuditLog::query()->create([
            'actor_id' => null,
            'action' => 'attendant.availability_changed',
            'subject_type' => $profile->getMorphClass(),
            'subject_id' => $profile->getKey(),
            'metadata' => [
                'from' => AttendantAvailability::Online->value,
                'to' => AttendantAvailability::Offline->value,
                'reason' => 'stale_presence_after_consultation',
            ],
        ]);
    }

    private function persist(
        Consultation $consultation,
        string $field,
        string $duplicateOpenMessage = 'O cliente já possui uma solicitação ou consulta em aberto.',
        string $duplicateActiveMessage = 'O atendente já possui uma consulta ativa.',
    ): void {
        try {
            $consultation->save();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        } catch (QueryException $exception) {
            $message = $exception->getMessage();

            if (str_contains($message, 'open_client_id')) {
                throw ValidationException::withMessages([$field => $duplicateOpenMessage]);
            }

            if (str_contains($message, 'active_attendant_id')) {
                throw ValidationException::withMessages([$field => $duplicateActiveMessage]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function audit(?int $actorId, string $action, Consultation $consultation, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $consultation->getMorphClass(),
            'subject_id' => $consultation->getKey(),
            'metadata' => $metadata,
        ]);
    }

    private function formatCents(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
