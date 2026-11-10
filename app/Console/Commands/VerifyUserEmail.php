<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\FirstAccessBonus;
use App\Support\Money;
use Illuminate\Console\Command;
use Throwable;

/**
 * Confirma o e-mail de uma conta sem depender do link enviado pelo correio.
 *
 * Serve para desbloquear o acesso de quem não recebeu (ou perdeu) o link: o
 * middleware `verified` libera os painéis assim que `users.email_verified_at`
 * é preenchido — a mesma coluna que `EmailVerificationController::verify()`
 * grava ao abrir o link assinado.
 *
 * O comando não envia e-mail e, por padrão, não mexe em créditos: o bônus de
 * primeiro acesso só é concedido com `--bonus`, porque é uma operação
 * financeira e precisa ser explícita. A alteração fica registrada em
 * `audit_logs` com o ator nulo (execução por linha de comando).
 */
class VerifyUserEmail extends Command
{
    protected $signature = 'arcana:verify-user
        {user : ID do usuário que terá o e-mail confirmado}
        {--bonus : Concede o bônus de primeiro acesso, como o link de confirmação faz}';

    protected $description = 'Marca o e-mail de um usuário como confirmado a partir do ID, sem enviar o link';

    public function handle(FirstAccessBonus $bonus): int
    {
        $id = trim((string) $this->argument('user'));

        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error("ID inválido: \"{$id}\". Informe o ID numérico do usuário, por exemplo: php artisan arcana:verify-user 12");

            return self::FAILURE;
        }

        $user = User::query()->find((int) $id);

        if ($user === null) {
            $this->error("Usuário #{$id} não encontrado.");

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->info(sprintf(
                'O e-mail %s do usuário #%d já estava confirmado em %s.',
                $user->email,
                $user->getKey(),
                $user->email_verified_at?->format('d/m/Y H:i:s'),
            ));

            return self::SUCCESS;
        }

        if (! $user->markEmailAsVerified()) {
            $this->error("Não foi possível gravar a confirmação de e-mail do usuário #{$id}.");

            return self::FAILURE;
        }

        AuditLog::query()->create([
            'actor_id' => null,
            'action' => 'user.email_verified_by_operator',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->getKey(),
            'metadata' => [
                'email' => $user->email,
                'via' => 'artisan',
                'command' => 'arcana:verify-user',
            ],
        ]);

        $this->info(sprintf(
            'E-mail confirmado para %s <%s> (usuário #%d).',
            $user->name,
            $user->email,
            $user->getKey(),
        ));

        if ($this->option('bonus')) {
            $this->grantBonus($user, $bonus);
        }

        return self::SUCCESS;
    }

    /**
     * A confirmação já está gravada: uma falha no bônus é reportada, mas não
     * desfaz a liberação do acesso.
     */
    private function grantBonus(User $user, FirstAccessBonus $bonus): void
    {
        try {
            $entry = $bonus->grantFor($user);
        } catch (Throwable $exception) {
            report($exception);

            $this->warn('Bônus de primeiro acesso não concedido: '.$exception->getMessage());

            return;
        }

        if ($entry === null) {
            $this->line('Bônus de primeiro acesso não concedido (desligado, já recebido, sem tarifa de crédito ou usuário não é cliente).');

            return;
        }

        $this->line('Bônus de primeiro acesso creditado: '.Money::format($entry->amount_cents));
    }
}
