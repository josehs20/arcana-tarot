<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\ConsultationPresenceSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Presença e atividade do atendimento, sempre medidas no servidor.
 *
 * O batimento de presença mantém a sessão viva, mas não conta como atividade
 * do usuário: quem deixa a conversa aberta sem interagir também é encerrado
 * pelo tempo de inatividade. Atividade é atualizada ao abrir a tela, enviar
 * mensagem/carta e ao interagir com o chat.
 */
final class ConsultationActivityTracker
{
    public function __construct(private readonly ConsultationSafety $safety) {}

    public function heartbeat(
        Consultation $consultation,
        User $user,
        ?string $sessionToken = null,
        bool $countsAsActivity = false,
    ): ConsultationPresenceSession {
        $this->ensureActiveParticipant($consultation, $user);

        $token = $sessionToken !== null && trim($sessionToken) !== ''
            ? trim($sessionToken)
            : null;

        if ($token !== null && preg_match('/^[A-Za-z0-9]{40,64}$/', $token) !== 1) {
            throw ValidationException::withMessages([
                'session_token' => 'Sessão de presença inválida.',
            ]);
        }

        return DB::transaction(function () use ($consultation, $user, $token, $countsAsActivity): ConsultationPresenceSession {
            $now = now();
            $session = null;

            if ($token !== null) {
                $session = ConsultationPresenceSession::query()
                    ->where('session_token', $token)
                    ->lockForUpdate()
                    ->first();

                if ($session !== null && (
                    (int) $session->consultation_id !== (int) $consultation->getKey()
                    || (int) $session->user_id !== (int) $user->getKey()
                )) {
                    throw new AuthorizationException('Esta sessão de presença pertence a outro atendimento.');
                }
            }

            if ($session === null) {
                $session = new ConsultationPresenceSession([
                    'consultation_id' => $consultation->getKey(),
                    'user_id' => $user->getKey(),
                    'role' => $this->roleFor($consultation, $user),
                    'session_token' => $token ?? Str::random(48),
                    'connected_at' => $now,
                ]);
            }

            $session->fill([
                'last_seen_at' => $now,
                'disconnected_at' => null,
                'disconnect_reason' => null,
            ]);
            $session->save();

            if ($countsAsActivity) {
                $this->touchActivity($consultation, $user);
            }

            return $session->refresh();
        });
    }

    /**
     * Registra atividade do participante sem criar sessão de presença.
     */
    public function touchActivity(Consultation $consultation, User $user): void
    {
        $role = $this->roleFor($consultation, $user);
        $now = now();

        $attributes = [
            'last_activity_at' => $now,
            'client_last_activity_at' => $role === 'client' ? $now : $consultation->client_last_activity_at,
            'attendant_last_activity_at' => $role === 'attendant' ? $now : $consultation->attendant_last_activity_at,
        ];

        DB::table('consultations')
            ->where('id', $consultation->getKey())
            ->update($attributes + ['updated_at' => $now]);

        $consultation->forceFill($attributes);
    }

    /**
     * Encerra a sessão de presença do navegador. Sem token, encerra todas as
     * sessões abertas daquele participante (usado no logout).
     */
    public function disconnect(
        Consultation $consultation,
        User $user,
        ?string $sessionToken,
        string $reason,
    ): void {
        $query = ConsultationPresenceSession::query()
            ->where('consultation_id', $consultation->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('disconnected_at');

        if ($sessionToken !== null && trim($sessionToken) !== '') {
            $query->where('session_token', trim($sessionToken));
        }

        $query->update([
            'disconnected_at' => now(),
            'disconnect_reason' => Str::limit($reason, 40, ''),
            'updated_at' => now(),
        ]);
    }

    public function closeSessions(Consultation $consultation, string $reason): int
    {
        return ConsultationPresenceSession::query()
            ->where('consultation_id', $consultation->getKey())
            ->whereNull('disconnected_at')
            ->update([
                'disconnected_at' => now(),
                'disconnect_reason' => Str::limit($reason, 40, ''),
                'updated_at' => now(),
            ]);
    }

    /**
     * Fotografia da presença usada pela resposta do batimento.
     *
     * @return array{
     *     client: array{state: string, last_seen_at: string|null},
     *     attendant: array{state: string, last_seen_at: string|null},
     *     last_activity_at: string|null,
     *     inactivity_timeout_seconds: int,
     *     presence_tolerance_seconds: int,
     *     reconnection_tolerance_seconds: int
     * }
     */
    public function presence(Consultation $consultation): array
    {
        $presence = [
            'client' => ['state' => 'offline', 'last_seen_at' => null],
            'attendant' => ['state' => 'offline', 'last_seen_at' => null],
            'last_activity_at' => $consultation->last_activity_at?->toIso8601String(),
            'inactivity_timeout_seconds' => $this->safety->inactivityTimeoutSeconds(),
            'presence_tolerance_seconds' => $this->safety->presenceToleranceSeconds(),
            'reconnection_tolerance_seconds' => $this->safety->reconnectionToleranceSeconds(),
        ];

        if (! $consultation->isActive()) {
            return $presence;
        }

        $sessions = ConsultationPresenceSession::query()
            ->where('consultation_id', $consultation->getKey())
            ->whereIn('role', ['client', 'attendant'])
            ->orderByDesc('last_seen_at')
            ->get(['role', 'last_seen_at', 'disconnected_at']);

        $onlineCutoff = now()->subSeconds($this->safety->presenceToleranceSeconds());
        $graceCutoff = now()->subSeconds(
            $this->safety->presenceToleranceSeconds() + $this->safety->reconnectionToleranceSeconds(),
        );

        foreach (['client', 'attendant'] as $role) {
            $roleSessions = $sessions->where('role', $role);
            $lastSeen = $roleSessions->first()?->last_seen_at;

            if ($lastSeen === null) {
                continue;
            }

            $hasOnlineSession = $roleSessions->contains(
                fn (ConsultationPresenceSession $session): bool => $session->disconnected_at === null
                    && $session->last_seen_at !== null
                    && $session->last_seen_at->greaterThanOrEqualTo($onlineCutoff),
            );

            $state = $hasOnlineSession
                ? 'online'
                : ($lastSeen->greaterThanOrEqualTo($graceCutoff) ? 'reconnecting' : 'offline');

            $presence[$role] = [
                'state' => $state,
                'last_seen_at' => $lastSeen->toIso8601String(),
            ];
        }

        return $presence;
    }

    public function roleFor(Consultation $consultation, User $user): string
    {
        if ($consultation->isClient($user)) {
            return 'client';
        }

        if ($consultation->isAttendant($user)) {
            return 'attendant';
        }

        throw new AuthorizationException('Você não participa desta consulta.');
    }

    private function ensureActiveParticipant(Consultation $consultation, User $user): void
    {
        if (! $user->is_active || ! $consultation->hasParticipant($user)) {
            throw new AuthorizationException('Você não participa desta consulta.');
        }

        if (! $consultation->isActive()) {
            throw ValidationException::withMessages([
                'consultation' => 'Este atendimento não está mais ativo.',
            ]);
        }
    }
}
