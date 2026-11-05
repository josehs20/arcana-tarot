<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\CreditWalletManager;
use App\Support\FirstAccessBonus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bônus de créditos no primeiro acesso e a tela que o configura.
 *
 * O valor é sempre derivado da tarifa do crédito (`credit_unit_price_cents`),
 * entra no livro-razão com chave idempotente e é auditado. Sem tarifa o
 * cadastro continua funcionando, apenas sem bônus.
 */
class FirstAccessBonusTest extends TestCase
{
    use RefreshDatabase;

    private function bonus(): FirstAccessBonus
    {
        return app(FirstAccessBonus::class);
    }

    private function setCreditUnitPrice(int $cents): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'credit_unit_price_cents'],
            ['value' => (string) $cents, 'type' => 'integer'],
        );
    }

    private function enableBonus(int $credits): void
    {
        config([
            'authentication.first_access_bonus.enabled' => true,
            'authentication.first_access_bonus.credits' => $credits,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(): array
    {
        return [
            'name' => 'Cliente Bônus',
            'email' => 'cliente.bonus@example.com',
            'phone' => '(11) 98888-7777',
            'birth_date' => '1990-08-14',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ];
    }

    public function test_registration_without_the_bonus_keeps_the_wallet_empty(): void
    {
        config(['authentication.first_access_bonus.enabled' => false]);

        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('client.dashboard'));

        $user = User::query()->where('email', 'cliente.bonus@example.com')->sole();

        $this->assertSame(0, $user->wallet->fresh()->balance_cents);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_registration_grants_the_configured_credits_once(): void
    {
        $this->enableBonus(5);
        $this->setCreditUnitPrice(120);

        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('client.dashboard'));

        $user = User::query()->where('email', 'cliente.bonus@example.com')->sole();

        $this->assertSame(600, $user->wallet->fresh()->balance_cents);

        $entry = CreditLedgerEntry::query()->sole();

        $this->assertSame(600, $entry->amount_cents);
        $this->assertSame(FirstAccessBonus::idempotencyKeyFor($user), $entry->idempotency_key);

        $audit = AuditLog::query()->where('action', 'first_access_bonus.granted')->sole();
        $this->assertSame(5, $audit->metadata['credits']);
        $this->assertSame(600, $audit->metadata['amount_cents']);

        // Repetir a concessão (login social repetido, reprocessamento) não credita de novo.
        $this->assertNull($this->bonus()->grantFor($user));
        $this->assertSame(600, $user->wallet->fresh()->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }

    public function test_bonus_is_skipped_and_audited_when_the_credit_price_is_missing(): void
    {
        $this->enableBonus(3);

        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('client.dashboard'));

        $user = User::query()->where('email', 'cliente.bonus@example.com')->sole();

        $this->assertSame(0, $user->wallet->fresh()->balance_cents);
        $this->assertSame(0, CreditLedgerEntry::query()->count());

        $audit = AuditLog::query()->where('action', 'first_access_bonus.skipped')->sole();

        $this->assertSame('credit_unit_price_missing', $audit->metadata['reason']);
        $this->assertSame(3, $audit->metadata['credits']);
    }

    public function test_bonus_does_not_apply_to_non_clients(): void
    {
        $this->enableBonus(2);
        $this->setCreditUnitPrice(100);

        $attendant = User::factory()->attendant()->create();

        $this->assertNull($this->bonus()->grantFor($attendant));
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_wallet_manager_keeps_a_single_entry_for_the_bonus(): void
    {
        $this->enableBonus(1);
        $this->setCreditUnitPrice(90);

        $user = User::factory()->create();
        app(CreditWalletManager::class)->walletFor($user);

        $this->assertNotNull($this->bonus()->grantFor($user));
        $this->assertNull($this->bonus()->grantFor($user));
        $this->assertSame(90, $user->wallet->fresh()->balance_cents);
    }

    public function test_admin_can_view_and_update_the_bonus_settings(): void
    {
        $admin = User::factory()->admin()->create();
        $this->setCreditUnitPrice(100);

        $this->actingAs($admin)
            ->get(route('admin.finance.bonus.edit'))
            ->assertOk()
            ->assertSee('Bônus de primeiro acesso')
            ->assertSee('Configuração do bônus');

        $this->actingAs($admin)
            ->patch(route('admin.finance.bonus.update'), ['enabled' => '1', 'credits' => 10])
            ->assertRedirect(route('admin.finance.bonus.edit'))
            ->assertSessionHas('success');

        $this->assertSame('1', Setting::query()->where('key', FirstAccessBonus::ENABLED_SETTING_KEY)->value('value'));
        $this->assertSame('10', Setting::query()->where('key', FirstAccessBonus::CREDITS_SETTING_KEY)->value('value'));

        $audit = AuditLog::query()->where('action', 'first_access_bonus.updated')->sole();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(['enabled' => false, 'credits' => 0], $audit->metadata['before']);
        $this->assertSame(['enabled' => true, 'credits' => 10], $audit->metadata['after']);

        $this->actingAs($admin)
            ->get(route('admin.finance.bonus.edit'))
            ->assertOk()
            ->assertSee('R$ 10,00');

        $this->assertTrue($this->bonus()->isEnabled());
        $this->assertSame(10, $this->bonus()->credits());
        $this->assertSame(1000, $this->bonus()->amountCents());
    }

    public function test_admin_can_disable_the_bonus(): void
    {
        $admin = User::factory()->admin()->create();
        $this->setCreditUnitPrice(100);

        $this->bonus()->save(true, 8, $admin);

        $this->actingAs($admin)
            ->patch(route('admin.finance.bonus.update'), ['enabled' => '0', 'credits' => 8])
            ->assertRedirect(route('admin.finance.bonus.edit'))
            ->assertSessionHas('success');

        $this->assertSame('0', Setting::query()->where('key', FirstAccessBonus::ENABLED_SETTING_KEY)->value('value'));
        $this->assertFalse($this->bonus()->isEnabled());
    }

    public function test_only_admins_can_open_and_change_the_bonus_settings(): void
    {
        $client = User::factory()->create();

        $this->get(route('admin.finance.bonus.edit'))->assertRedirect(route('login'));

        $this->actingAs($client)
            ->get(route('admin.finance.bonus.edit'))
            ->assertForbidden();

        $this->actingAs($client)
            ->patch(route('admin.finance.bonus.update'), ['enabled' => '1', 'credits' => 5])
            ->assertForbidden();

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_enabling_the_bonus_requires_credits_and_a_configured_credit_price(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.finance.bonus.edit'))
            ->patch(route('admin.finance.bonus.update'), ['enabled' => '1', 'credits' => 0])
            ->assertRedirect(route('admin.finance.bonus.edit'))
            ->assertSessionHasErrors('credits');

        $this->actingAs($admin)
            ->from(route('admin.finance.bonus.edit'))
            ->patch(route('admin.finance.bonus.update'), ['enabled' => '1', 'credits' => 5])
            ->assertRedirect(route('admin.finance.bonus.edit'))
            ->assertSessionHasErrors('enabled');

        $this->assertSame(0, Setting::query()->where('key', FirstAccessBonus::ENABLED_SETTING_KEY)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'first_access_bonus.updated')->count());
    }
}
