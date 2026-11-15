<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Throwable;

/**
 * Efeitos colaterais de entrar e sair da conta.
 *
 * Vale para sessão web e para tokens de API: ao autenticar, o atendente fica
 * online; ao encerrar a sessão, a consulta ativa é encerrada e a
 * disponibilidade volta para offline. Falhas nesses efeitos são reportadas e
 * nunca impedem a autenticação ou o logout de concluir.
 */
final class AuthenticationLifecycleManager
{
    public function __construct(
        private readonly AttendantAvailabilityManager $availability,
        private readonly ConsultationLifecycleManager $lifecycle,
    ) {}

    public function afterLogin(User $user): void
    {
        if ($user->role !== UserRole::Attendant) {
            return;
        }

        try {
            $this->availability->signIn($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function afterLogout(User $user): void
    {
        try {
            $this->lifecycle->endActiveForLogout($user);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($user->role !== UserRole::Attendant) {
            return;
        }

        try {
            $this->availability->signOut($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
