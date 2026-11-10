<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comando de confirmação manual de e-mail (arcana:verify-user).
 *
 * É o atalho de operação para quando o link não chega: grava a mesma coluna
 * que o link assinado (`users.email_verified_at`), audita a alteração e só
 * mexe em créditos com `--bonus`.
 */
class VerifyUserEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_confirms_the_email_of_an_unverified_user(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'bloqueado@gmail.com']);

        $this->artisan('arcana:verify-user', ['user' => (string) $user->getKey()])
            ->expectsOutputToContain('E-mail confirmado para')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => null,
            'action' => 'user.email_verified_by_operator',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->getKey(),
        ]);
    }

    public function test_command_keeps_an_already_verified_account_untouched(): void
    {
        $verifiedAt = now()->subDays(3)->startOfSecond();

        $user = User::factory()->create([
            'email' => 'confirmado@gmail.com',
            'email_verified_at' => $verifiedAt,
        ]);

        $this->artisan('arcana:verify-user', ['user' => (string) $user->getKey()])
            ->expectsOutputToContain('já estava confirmado em')
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->email_verified_at->equalTo($verifiedAt));
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'user.email_verified_by_operator')
            ->count());
    }

    public function test_command_fails_for_an_unknown_user(): void
    {
        $this->artisan('arcana:verify-user', ['user' => '999'])
            ->expectsOutputToContain('Usuário #999 não encontrado.')
            ->assertExitCode(1);
    }

    public function test_command_rejects_a_non_numeric_id(): void
    {
        $this->artisan('arcana:verify-user', ['user' => 'cliente@gmail.com'])
            ->expectsOutputToContain('ID inválido')
            ->assertExitCode(1);
    }

    public function test_command_does_not_touch_credits_without_the_bonus_option(): void
    {
        $this->enableBonus();
        $this->setCreditUnitPrice(120);

        $user = User::factory()->unverified()->create();

        $this->artisan('arcana:verify-user', ['user' => (string) $user->getKey()])
            ->assertExitCode(0);

        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertNull(Wallet::query()->where('user_id', $user->getKey())->first());
    }

    public function test_bonus_option_grants_the_first_access_credits(): void
    {
        $this->enableBonus();
        $this->setCreditUnitPrice(120);

        $user = User::factory()->unverified()->create();

        $this->artisan('arcana:verify-user', [
            'user' => (string) $user->getKey(),
            '--bonus' => true,
        ])
            ->expectsOutputToContain('Bônus de primeiro acesso creditado: R$ 6,00')
            ->assertExitCode(0);

        $entry = CreditLedgerEntry::query()->sole();

        $this->assertSame(600, $entry->amount_cents);
        $this->assertSame(600, Wallet::query()->where('user_id', $user->getKey())->sole()->balance_cents);

        // Repetir o comando não credita de novo: a confirmação já existe e o
        // bônus é idempotente.
        $this->artisan('arcana:verify-user', [
            'user' => (string) $user->getKey(),
            '--bonus' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(600, Wallet::query()->where('user_id', $user->getKey())->sole()->balance_cents);
    }

    private function enableBonus(): void
    {
        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => 5,
        ]);
    }

    private function setCreditUnitPrice(int $cents): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'credit_unit_price_cents'],
            ['value' => (string) $cents, 'type' => 'integer'],
        );
    }
}
