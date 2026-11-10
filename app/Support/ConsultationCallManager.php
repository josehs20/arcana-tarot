<?php

namespace App\Support;

use App\Enums\CallEndReason;
use App\Enums\CallSignalType;
use App\Enums\CallStatus;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Events\ConsultationCallUpdated;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationCall;
use App\Models\ConsultationCallSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Negociacao de chamadas de audio e video (WebRTC).
 *
 * O servidor transporta apenas sinalizacao (oferta, resposta e candidatos
 * ICE) e controla o ciclo de vida da chamada. A midia nunca passa pelo
 * Laravel. Toda transicao roda em transacao com lock pessimista e possui
 * chave natural (uma chamada por consulta), portanto repetir a solicitacao
 * nao abre duas sessoes nem duplica cobrancas.
 */
final class ConsultationCallManager
{
    public function __construct(
        private readonly ConsultationLifecycleManager $lifecycle,
        private readonly ConsultationModalityCatalog $modalities,
    ) {}

    /**
     * Cliente solicita a chamada. Repetir a acao com chamada aberta devolve a
     * mesma chamada (idempotente).
     */
    public function request(Consultation $consultation, User $actor): ConsultationCall
    {
        $this->assertParticipant($consultation, $actor);

        if (! $consultation->isClient($actor)) {
            throw new AuthorizationException('Apenas o cliente pode solicitar a chamada.');
        }

        $call = DB::transaction(function () use ($consultation, $actor): ConsultationCall {
            $locked = $this->lockConsultation($consultation);
            $this->assertCallable($locked);

            $existing = ConsultationCall::query()
                ->where('consultation_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->isOpen()) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'call' => 'Esta chamada já foi encerrada. Abra uma nova consulta para chamar novamente.',
                ]);
            }

            try {
                $call = ConsultationCall::query()->create([
                    'consultation_id' => $locked->getKey(),
                    'requested_by_id' => $actor->getKey(),
                    'modality' => $locked->modality->value,
                    'status' => CallStatus::Ringing->value,
                ]);
            } catch (QueryException $exception) {
                $call = ConsultationCall::query()
                    ->where('consultation_id', $locked->getKey())
                    ->first();

                if ($call === null) {
                    throw $exception;
                }
            }

            $this->audit($actor, 'consultation.call_requested', $locked, [
                'call_id' => $call->getKey(),
                'modality' => $locked->modality->value,
            ]);

            return $call;
        });

        $this->broadcast($consultation, $call);

        return $call;
    }

    public function accept(Consultation $consultation, User $actor): ConsultationCall
    {
        $this->assertParticipant($consultation, $actor);

        if (! $consultation->isAttendant($actor)) {
            throw new AuthorizationException('Apenas o atendente pode aceitar a chamada.');
        }

        $call = DB::transaction(function () use ($consultation, $actor): ConsultationCall {
            $locked = $this->lockConsultation($consultation);
            $this->assertCallable($locked);

            $call = $this->lockCall($locked);

            if ($call->status === CallStatus::Accepted || $call->status === CallStatus::Connected) {
                return $call;
            }

            if ($call->status !== CallStatus::Ringing) {
                throw ValidationException::withMessages([
                    'call' => 'Esta chamada não está mais aguardando aceite.',
                ]);
            }

            $call->fill([
                'status' => CallStatus::Accepted->value,
                'accepted_at' => now(),
            ])->save();

            $this->audit($actor, 'consultation.call_accepted', $locked, ['call_id' => $call->getKey()]);

            return $call;
        });

        $this->broadcast($consultation, $call);

        return $call;
    }

    /**
     * Atendente recusa a chamada. A consulta e encerrada para nao consumir
     * creditos sem atendimento.
     */
    public function reject(Consultation $consultation, User $actor): ConsultationCall
    {
        $this->assertParticipant($consultation, $actor);

        if (! $consultation->isAttendant($actor)) {
            throw new AuthorizationException('Apenas o atendente pode recusar a chamada.');
        }

        return $this->finish($consultation, $actor, CallEndReason::Rejected);
    }

    /**
     * Confirma que a peer connection foi estabelecida no navegador.
     */
    public function markConnected(Consultation $consultation, User $actor): ConsultationCall
    {
        $this->assertParticipant($consultation, $actor);

        $call = DB::transaction(function () use ($consultation, $actor): ConsultationCall {
            $locked = $this->lockConsultation($consultation);
            $call = $this->lockCall($locked);

            if ($call->status === CallStatus::Connected) {
                return $call;
            }

            if ($call->status !== CallStatus::Accepted) {
                throw ValidationException::withMessages([
                    'call' => 'A chamada precisa ser aceita antes de conectar.',
                ]);
            }

            $call->fill([
                'status' => CallStatus::Connected->value,
                'connected_at' => now(),
            ])->save();

            $this->audit($actor, 'consultation.call_connected', $locked, ['call_id' => $call->getKey()]);

            return $call;
        });

        $this->broadcast($consultation, $call);

        return $call;
    }

    /**
     * Encerramento por um participante (desligar). Encerra a consulta e
     * liquida os minutos ja iniciados pelas regras existentes.
     */
    public function end(Consultation $consultation, User $actor): ConsultationCall
    {
        $this->assertParticipant($consultation, $actor);

        return $this->finish($consultation, $actor, CallEndReason::Hangup);
    }

    /**
     * Falha de midia, permissao ou conexao. Encerra a chamada e a consulta
     * para interromper a cobranca.
     */
    public function fail(Consultation $consultation, User $actor, CallEndReason $reason): ConsultationCall
    {
        $this->assertParticipant($consultation, $actor);

        if ($reason === CallEndReason::Hangup || $reason === CallEndReason::ConsultationEnded) {
            throw ValidationException::withMessages([
                'reason' => 'Motivo de falha inválido.',
            ]);
        }

        return $this->finish($consultation, $actor, $reason);
    }

    /**
     * Persiste um sinal WebRTC opaco para o outro participante.
     */
    public function storeSignal(
        Consultation $consultation,
        User $actor,
        CallSignalType $type,
        array $payload,
    ): ConsultationCallSignal {
        $this->assertParticipant($consultation, $actor);

        $this->assertPayloadSize($payload);

        $signal = DB::transaction(function () use ($consultation, $actor, $type, $payload): ConsultationCallSignal {
            $locked = $this->lockConsultation($consultation);

            if ($locked->status !== ConsultationStatus::Active) {
                throw ValidationException::withMessages([
                    'call' => 'A consulta não está ativa.',
                ]);
            }

            $call = ConsultationCall::query()
                ->where('consultation_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($call === null || ! in_array($call->status, [CallStatus::Accepted, CallStatus::Connected], true)) {
                throw ValidationException::withMessages([
                    'call' => 'A chamada não está pronta para troca de sinais.',
                ]);
            }

            $count = ConsultationCallSignal::query()
                ->where('consultation_id', $locked->getKey())
                ->count();

            if ($count >= (int) config('consultation.web_rtc.signal_max_per_call', 400)) {
                throw ValidationException::withMessages([
                    'call' => 'Limite de sinalização da chamada atingido.',
                ]);
            }

            return ConsultationCallSignal::query()->create([
                'consultation_id' => $locked->getKey(),
                'from_user_id' => $actor->getKey(),
                'type' => $type->value,
                'payload' => $payload,
            ]);
        });

        $this->broadcast($consultation, $this->currentCall($consultation), $signal);

        return $signal;
    }

    /**
     * @return Collection<int, ConsultationCallSignal>
     */
    public function signalsAfter(Consultation $consultation, int $afterId = 0, int $limit = 120): Collection
    {
        return ConsultationCallSignal::query()
            ->where('consultation_id', $consultation->getKey())
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->limit(max(1, min(300, $limit)))
            ->get();
    }

    public function currentCall(Consultation $consultation): ?ConsultationCall
    {
        return ConsultationCall::query()
            ->where('consultation_id', $consultation->getKey())
            ->first();
    }

    /**
     * Encerra chamadas que ficaram tocando alem do limite configurado ou que
     * foram aceitas mas nunca chegaram a conectar. Sem isso, uma chamada
     * abandonada continuaria consumindo creditos por minuto.
     */
    public function expireStaleCalls(): int
    {
        $cutoff = now()->subSeconds((int) config('consultation.web_rtc.ring_timeout_seconds', 90));

        $ids = ConsultationCall::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($query) use ($cutoff): void {
                    $query->where('status', CallStatus::Ringing->value)
                        ->where('created_at', '<=', $cutoff);
                })->orWhere(function ($query) use ($cutoff): void {
                    $query->where('status', CallStatus::Accepted->value)
                        ->where('accepted_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->pluck('id');

        $closed = 0;

        foreach ($ids as $id) {
            $call = ConsultationCall::query()->find($id);

            if ($call === null || ! in_array($call->status, [CallStatus::Ringing, CallStatus::Accepted], true)) {
                continue;
            }

            $consultation = Consultation::query()->find($call->consultation_id);

            if ($consultation === null) {
                continue;
            }

            try {
                $this->finishBySystem($consultation, CallEndReason::Timeout);
                $closed++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $closed;
    }

    private function finish(Consultation $consultation, User $actor, CallEndReason $reason): ConsultationCall
    {
        $call = DB::transaction(function () use ($consultation, $actor, $reason): ConsultationCall {
            $locked = $this->lockConsultation($consultation);
            $call = $this->lockCall($locked);

            if ($call->isOpen()) {
                $call->fill([
                    'status' => $reason === CallEndReason::Hangup ? CallStatus::Ended->value : CallStatus::Failed->value,
                    'ended_reason' => $reason->value,
                    'ended_at' => now(),
                ])->save();

                $this->concludeConsultation($locked, $actor, $reason);

                $this->audit($actor, 'consultation.call_ended', $locked, [
                    'call_id' => $call->getKey(),
                    'reason' => $reason->value,
                    'status' => $call->status?->value,
                ]);
            }

            return $call;
        });

        $this->broadcast($consultation, $call->refresh());

        return $call;
    }

    private function finishBySystem(Consultation $consultation, CallEndReason $reason): void
    {
        DB::transaction(function () use ($consultation, $reason): void {
            $locked = $this->lockConsultation($consultation);
            $call = $this->lockCall($locked);

            if (! $call->isOpen()) {
                return;
            }

            $call->fill([
                'status' => CallStatus::Failed->value,
                'ended_reason' => $reason->value,
                'ended_at' => now(),
            ])->save();

            $this->concludeConsultation($locked, null, $reason);

            $this->audit(null, 'consultation.call_ended', $locked, [
                'call_id' => $call->getKey(),
                'reason' => $reason->value,
                'status' => CallStatus::Failed->value,
            ]);
        });

        $this->broadcast($consultation, $this->currentCall($consultation));
    }

    private function concludeConsultation(Consultation $consultation, ?User $actor, CallEndReason $reason): void
    {
        if ($consultation->status?->isFinal()) {
            return;
        }

        if ($reason === CallEndReason::Hangup && $actor !== null && $consultation->status === ConsultationStatus::Active) {
            $this->lifecycle->end($actor, $consultation);

            return;
        }

        if ($consultation->status === ConsultationStatus::Active) {
            $this->lifecycle->endBySystem($consultation, ConsultationEndReason::CallFailed);
        }
    }

    private function assertCallable(Consultation $consultation): void
    {
        if ($consultation->status !== ConsultationStatus::Active) {
            throw ValidationException::withMessages([
                'call' => 'A chamada só pode começar com a consulta ativa.',
            ]);
        }

        $modality = $consultation->modality;

        if ($modality === null || ! $modality->requiresCall()) {
            throw ValidationException::withMessages([
                'call' => 'Esta consulta não possui chamada: '.(($modality?->allowsVoiceMessages() ?? false)
                    ? 'no atendimento por áudio a voz é enviada pelo chat.'
                    : 'somente o atendimento por vídeo abre chamada.'),
            ]);
        }

        if (! $this->modalities->isEnabled($modality)) {
            throw ValidationException::withMessages([
                'call' => 'A chamada por '.$modality->label().' não está disponível no momento.',
            ]);
        }
    }

    private function assertParticipant(Consultation $consultation, User $actor): void
    {
        if (! $actor->is_active || ! $consultation->hasParticipant($actor)) {
            throw new AuthorizationException('Você não participa desta consulta.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadSize(array $payload): void
    {
        $encoded = json_encode($payload);

        if ($encoded === false || strlen($encoded) > (int) config('consultation.web_rtc.signal_max_bytes', 65536)) {
            throw ValidationException::withMessages([
                'payload' => 'O sinal da chamada excede o tamanho permitido.',
            ]);
        }

        if ($payload === []) {
            throw ValidationException::withMessages([
                'payload' => 'O sinal da chamada está vazio.',
            ]);
        }
    }

    private function lockConsultation(Consultation $consultation): Consultation
    {
        return Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
    }

    private function lockCall(Consultation $consultation): ConsultationCall
    {
        $call = ConsultationCall::query()
            ->where('consultation_id', $consultation->getKey())
            ->lockForUpdate()
            ->first();

        if ($call === null) {
            throw ValidationException::withMessages([
                'call' => 'Nenhuma chamada em andamento nesta consulta.',
            ]);
        }

        return $call;
    }

    private function broadcast(Consultation $consultation, ?ConsultationCall $call, ?ConsultationCallSignal $signal = null): void
    {
        try {
            ConsultationCallUpdated::dispatch(
                (int) $consultation->getKey(),
                ConsultationCallPresenter::toArray($call),
                $signal === null ? null : ConsultationCallPresenter::signal($signal),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function audit(?User $actor, string $action, Consultation $consultation, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $consultation->getMorphClass(),
            'subject_id' => $consultation->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
