<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CreditPackages;
use App\Support\CreditWalletManager;
use App\Support\PaymentLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): CreditWalletManager
    {
        return app(CreditWalletManager::class);
    }

    private function reference(string $key): AuditLog
    {
        return AuditLog::query()->create([
            'action' => 'test.ledger',
            'metadata' => ['key' => $key],
        ]);
    }

    private function credit(User $user, int $amountCents, string $idempotencyKey): CreditLedgerEntry
    {
        return $this->manager()->credit(
            $user,
            $amountCents,
            $idempotencyKey,
            $this->reference($idempotencyKey),
        );
    }

    private function debit(User $user, int $amountCents, string $idempotencyKey): CreditLedgerEntry
    {
        return $this->manager()->debit(
            $user,
            $amountCents,
            $idempotencyKey,
            $this->reference($idempotencyKey),
        );
    }

    public function test_client_sees_only_own_wallet_history(): void
    {
        $client = User::factory()->create();
        $otherClient = User::factory()->create();

        $this->credit($client, 2500, 'history:client:credit');
        $this->debit($client, 500, 'history:client:debit');
        $this->credit($otherClient, 9999, 'history:other:credit');

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('R$ 20,00')
            ->assertSee('Crédito')
            ->assertSee('Débito')
            ->assertSee('R$ 25,00')
            ->assertSee('R$ 5,00')
            ->assertDontSee('R$ 99,99');
    }

    public function test_client_cannot_submit_balance_or_debit_values(): void
    {
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 700]);

        $this->actingAs($client)
            ->post(route('client.credits.store'), [
                'balance_cents' => 999999,
                'amount_cents' => 999999,
                'type' => 'credit',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(700, $client->wallet->fresh()->balance_cents);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_client_cannot_access_admin_finance_or_adjust_wallets(): void
    {
        $client = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($client)
            ->get(route('admin.finance.index'))
            ->assertForbidden();

        $this->actingAs($client)
            ->get(route('admin.finance.wallets.show', $target))
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('admin.finance.wallets.adjust', $target), [
                'type' => 'credit',
                'amount_cents' => 1000,
                'reason' => 'Tentativa indevida',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_inactive_admin_cannot_manage_wallets(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => false]);
        $client = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.finance.wallets.adjust', $client), [
                'type' => 'credit',
                'amount_cents' => 1000,
                'reason' => 'Tentativa indevida',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'wallet.adjustment')->count());
    }

    public function test_admin_can_view_the_financial_ledger_and_a_client_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['name' => 'Cliente Financeiro']);
        $manager = $this->manager();

        $manager->adjust(
            $client,
            $admin,
            LedgerEntryType::Credit,
            1500,
            'Cortesia aprovada pela administração',
            'finance-view-key',
        );

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee('Cliente Financeiro')
            ->assertSee($client->email)
            ->assertSee('Cortesia aprovada pela administração')
            ->assertSee('R$ 15,00');

        $this->actingAs($admin)
            ->get(route('admin.finance.wallets.show', $client))
            ->assertOk()
            ->assertSee('Saldo da carteira')
            ->assertSee('Cortesia aprovada pela administração')
            ->assertSee('R$ 15,00');
    }

    public function test_admin_can_update_consultation_pricing_with_audit(): void
    {
        $admin = User::factory()->admin()->create();

        Setting::query()->create(['key' => 'rate_credits_per_minute', 'value' => '1', 'type' => 'integer']);
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);

        $this->actingAs($admin)
            ->patch(route('admin.finance.pricing.update'), [
                'rate_credits_per_minute_text' => 2,
                'rate_credits_per_minute_audio' => 3,
                'rate_credits_per_minute_video' => 5,
                'credit_unit_price_cents' => 150,
                'minimum_balance_minutes' => 3,
            ])
            ->assertRedirect(route('admin.finance.index'))
            ->assertSessionHas('success');

        $this->assertSame('2', Setting::query()->where('key', 'rate_credits_per_minute')->value('value'));
        $this->assertSame('2', Setting::query()->where('key', 'rate_credits_per_minute_text')->value('value'));
        $this->assertSame('3', Setting::query()->where('key', 'rate_credits_per_minute_audio')->value('value'));
        $this->assertSame('5', Setting::query()->where('key', 'rate_credits_per_minute_video')->value('value'));
        $this->assertSame('150', Setting::query()->where('key', 'credit_unit_price_cents')->value('value'));
        $this->assertSame('3', Setting::query()->where('key', 'minimum_balance_minutes')->value('value'));

        $audit = AuditLog::query()->where('action', 'consultation_pricing.updated')->sole();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(1, $audit->metadata['before']['rate_credits_per_minute']);
        $this->assertSame(2, $audit->metadata['after']['rate_credits_per_minute']);
        $this->assertSame(3, $audit->metadata['after']['rate_credits_per_minute_audio']);
        $this->assertSame(5, $audit->metadata['after']['rate_credits_per_minute_video']);
        $this->assertSame(300, $audit->metadata['after']['rate_credits_per_minute'] * $audit->metadata['after']['credit_unit_price_cents']);

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee('Tarifas por modalidade')
            ->assertSee('R$ 3,00')
            ->assertSee('R$ 4,50')
            ->assertSee('R$ 7,50');
    }

    public function test_pricing_update_validates_values_and_blocks_non_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.finance.index'))
            ->patch(route('admin.finance.pricing.update'), [
                'rate_credits_per_minute_text' => 0,
                'rate_credits_per_minute_audio' => 0,
                'rate_credits_per_minute_video' => 0,
                'credit_unit_price_cents' => 0,
                'minimum_balance_minutes' => 0,
            ])
            ->assertRedirect(route('admin.finance.index'))
            ->assertSessionHasErrors([
                'rate_credits_per_minute_text',
                'rate_credits_per_minute_audio',
                'rate_credits_per_minute_video',
                'credit_unit_price_cents',
                'minimum_balance_minutes',
            ]);

        $this->actingAs($client)
            ->patch(route('admin.finance.pricing.update'), [
                'rate_credits_per_minute_text' => 2,
                'rate_credits_per_minute_audio' => 3,
                'rate_credits_per_minute_video' => 5,
                'credit_unit_price_cents' => 150,
                'minimum_balance_minutes' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'consultation_pricing.updated')->count());
    }

    public function test_admin_can_configure_the_credit_packages_used_by_the_checkout(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);

        config([
            'payments.provider' => 'infinitepay',
            'payments.infinitepay.handle' => 'arcana',
            'payments.min_amount_cents' => 100,
            'payments.max_amount_cents' => 1_000_000,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.finance.packages.update'), [
                'packages' => [
                    ['credits' => 150, 'label' => 'Pacote inicial'],
                    ['credits' => 500, 'label' => ''],
                ],
            ])
            ->assertRedirect(route('admin.finance.packages.index'))
            ->assertSessionHas('success');

        $setting = Setting::query()->where('key', CreditPackages::SETTING_KEY)->sole();

        $this->assertSame('json', $setting->type);
        $this->assertSame([
            ['credits' => 150, 'label' => 'Pacote inicial'],
            ['credits' => 500, 'label' => null],
        ], json_decode((string) $setting->value, true));

        $audit = AuditLog::query()->where('action', 'credit_packages.updated')->sole();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertCount(4, $audit->metadata['before']);
        $this->assertCount(2, $audit->metadata['after']);
        $this->assertSame('credits_150', $audit->metadata['after'][0]['key']);

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('Pacote inicial')
            ->assertSee('R$ 150,00')
            ->assertSee('500 créditos')
            ->assertSee('R$ 500,00');

        $this->actingAs($admin)
            ->get(route('admin.finance.packages.index'))
            ->assertOk()
            ->assertSee('Pacotes de créditos do checkout')
            ->assertSee('Pacote inicial')
            ->assertSee('name="packages[0][credits]"', false)
            /*
             * O editor (resources/js/credit-packages.js) procura o template a
             * partir do formulário; se ele ficar fora do <form>, o botão
             * "Adicionar pacote" não faz nada.
             */
            ->assertSeeInOrder(['data-credit-packages-form', 'data-package-template', '</form>'], false);
    }

    public function test_credit_package_update_validates_ranges_and_blocks_non_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        // R$ 10,00 por crédito: 5.000 créditos custariam R$ 50.000,00 (acima do máximo).
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '1000', 'type' => 'integer']);

        config([
            'payments.min_amount_cents' => 100,
            'payments.max_amount_cents' => 1_000_000,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.finance.packages.index'))
            ->patch(route('admin.finance.packages.update'), [
                'packages' => [
                    ['credits' => 0, 'label' => ''],
                    ['credits' => 5, 'label' => ''],
                    ['credits' => 5, 'label' => ''],
                    ['credits' => 5000, 'label' => ''],
                ],
            ])
            ->assertRedirect(route('admin.finance.packages.index'))
            ->assertSessionHasErrors([
                'packages.0.credits',
                'packages.2.credits',
                'packages.3.credits',
            ]);

        $this->assertSame(0, Setting::query()->where('key', CreditPackages::SETTING_KEY)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'credit_packages.updated')->count());

        $this->actingAs($client)
            ->patch(route('admin.finance.packages.update'), [
                'packages' => [['credits' => 10, 'label' => '']],
            ])
            ->assertForbidden();

        $this->assertSame(0, Setting::query()->where('key', CreditPackages::SETTING_KEY)->count());
    }

    public function test_checkout_packages_fall_back_to_config_until_the_admin_saves_a_catalog(): void
    {
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);

        config(['payments.packages' => ['credits_10' => 10, 'credits_30' => 30]]);

        $packages = app(CreditPackages::class);

        $this->assertSame(['credits_10', 'credits_30'], $packages->keys());
        $this->assertSame([
            'key' => 'credits_10',
            'credits' => 10,
            'amount_cents' => 1000,
            'credit_unit_price_cents' => 100,
            'label' => '10 créditos',
        ], $packages->find('credits_10'));
    }

    public function test_admin_is_warned_when_a_saved_package_falls_outside_the_recharge_limits(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        // R$ 50,00 por crédito: 500 créditos custariam R$ 25.000,00 (acima do máximo).
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '5000', 'type' => 'integer']);
        Setting::query()->create([
            'key' => CreditPackages::SETTING_KEY,
            'value' => json_encode([['credits' => 500, 'label' => 'Pacote caro']], JSON_UNESCAPED_UNICODE),
            'type' => 'json',
        ]);

        config([
            'payments.provider' => 'infinitepay',
            'payments.infinitepay.handle' => 'arcana',
            'payments.max_amount_cents' => 1_000_000,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance.packages.index'))
            ->assertOk()
            ->assertSee('Pacote caro')
            ->assertSee('do catálogo estão fora dos limites de recarga');

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertDontSee('Pacote caro');
    }

    public function test_admin_updates_the_recharge_limits_and_the_checkout_follows_them(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);
        Setting::query()->create([
            'key' => CreditPackages::SETTING_KEY,
            'value' => json_encode([
                ['credits' => 20, 'label' => null],
                ['credits' => 100, 'label' => null],
            ], JSON_UNESCAPED_UNICODE),
            'type' => 'json',
        ]);

        config([
            'payments.provider' => 'infinitepay',
            'payments.infinitepay.handle' => 'arcana',
            'payments.min_amount_cents' => 100,
            'payments.max_amount_cents' => 1_000_000,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.finance.packages.limits.update'), [
                'min_amount_reais' => '10,00',
                'max_amount_reais' => '50,00',
            ])
            ->assertRedirect(route('admin.finance.packages.index'))
            ->assertSessionHas('success');

        $this->assertSame('1000', (string) Setting::query()->where('key', PaymentLimits::MIN_SETTING_KEY)->sole()->value);
        $this->assertSame('5000', (string) Setting::query()->where('key', PaymentLimits::MAX_SETTING_KEY)->sole()->value);

        $audit = AuditLog::query()->where('action', 'payment_limits.updated')->sole();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(
            ['min_amount_cents' => 100, 'max_amount_cents' => 1_000_000],
            $audit->metadata['before'],
        );
        $this->assertSame(
            ['min_amount_cents' => 1000, 'max_amount_cents' => 5000],
            $audit->metadata['after'],
        );

        $this->actingAs($admin)
            ->get(route('admin.finance.packages.index'))
            ->assertOk()
            ->assertSee('R$ 10,00 a R$ 50,00')
            ->assertSee('100 créditos')
            ->assertSee('fora dos limites de recarga');

        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSee('20 créditos')
            ->assertDontSee('100 créditos');
    }

    public function test_recharge_limits_require_one_credit_of_minimum_and_ordered_values(): void
    {
        $admin = User::factory()->admin()->create();

        // R$ 5,00 por crédito: um mínimo de R$ 1,00 não cobriria 1 crédito.
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '500', 'type' => 'integer']);

        $this->actingAs($admin)
            ->from(route('admin.finance.packages.index'))
            ->patch(route('admin.finance.packages.limits.update'), [
                'min_amount_reais' => '1,00',
                'max_amount_reais' => '200,00',
            ])
            ->assertRedirect(route('admin.finance.packages.index'))
            ->assertSessionHasErrors('min_amount_cents');

        $this->actingAs($admin)
            ->from(route('admin.finance.packages.index'))
            ->patch(route('admin.finance.packages.limits.update'), [
                'min_amount_reais' => '50,00',
                'max_amount_reais' => '10,00',
            ])
            ->assertRedirect(route('admin.finance.packages.index'))
            ->assertSessionHasErrors('max_amount_cents');

        $this->actingAs($admin)
            ->from(route('admin.finance.packages.index'))
            ->patch(route('admin.finance.packages.limits.update'), [
                'min_amount_reais' => 'abc',
                'max_amount_reais' => '',
            ])
            ->assertRedirect(route('admin.finance.packages.index'))
            ->assertSessionHasErrors(['min_amount_cents', 'max_amount_cents']);

        $this->assertSame(
            0,
            Setting::query()
                ->whereIn('key', [PaymentLimits::MIN_SETTING_KEY, PaymentLimits::MAX_SETTING_KEY])
                ->count(),
        );
        $this->assertSame(0, AuditLog::query()->where('action', 'payment_limits.updated')->count());
    }

    public function test_only_admins_can_open_and_change_the_payment_settings(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('admin.finance.packages.index'))
            ->assertForbidden();

        $this->actingAs($client)
            ->patch(route('admin.finance.packages.limits.update'), [
                'min_amount_reais' => '10,00',
                'max_amount_reais' => '50,00',
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            Setting::query()
                ->whereIn('key', [PaymentLimits::MIN_SETTING_KEY, PaymentLimits::MAX_SETTING_KEY])
                ->count(),
        );
        $this->assertSame(0, AuditLog::query()->where('action', 'payment_limits.updated')->count());
    }

    public function test_admin_adjustment_requires_reason_and_valid_values(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.finance.wallets.show', $client))
            ->post(route('admin.finance.wallets.adjust', $client), [
                'type' => 'invalid',
                'amount_cents' => 0,
                'operation_id' => 'not-a-uuid',
            ])
            ->assertRedirect(route('admin.finance.wallets.show', $client))
            ->assertSessionHasErrors(['type', 'amount_cents', 'reason', 'operation_id']);

        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'wallet.adjustment')->count());
    }

    public function test_admin_adjustment_writes_ledger_and_audit_once_even_when_resubmitted(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $operationId = (string) Str::uuid();

        $payload = [
            'type' => LedgerEntryType::Credit->value,
            'amount_cents' => 1500,
            'reason' => 'Crédito de cortesia aprovado',
            'operation_id' => $operationId,
        ];

        $this->actingAs($admin)
            ->post(route('admin.finance.wallets.adjust', $client), $payload)
            ->assertRedirect(route('admin.finance.wallets.show', $client))
            ->assertSessionHas('success');

        $entry = CreditLedgerEntry::query()->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(1500, $wallet->balance_cents);
        $this->assertSame($admin->id, $entry->created_by);
        $this->assertSame('Crédito de cortesia aprovado', $entry->reason);
        $this->assertSame(AuditLog::class, $entry->reference_type);
        $this->assertSame(1, AuditLog::query()->where('action', 'wallet.adjustment')->count());

        $this->actingAs($admin)
            ->post(route('admin.finance.wallets.adjust', $client), $payload)
            ->assertRedirect(route('admin.finance.wallets.show', $client))
            ->assertSessionHas('success');

        $this->assertSame(1500, $wallet->fresh()->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'wallet.adjustment')->count());
    }

    public function test_admin_debit_cannot_leave_a_negative_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $this->credit($client, 500, 'debit-limit:credit');

        $this->actingAs($admin)
            ->from(route('admin.finance.wallets.show', $client))
            ->post(route('admin.finance.wallets.adjust', $client), [
                'type' => LedgerEntryType::Debit->value,
                'amount_cents' => 600,
                'reason' => 'Débito acima do saldo',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.finance.wallets.show', $client))
            ->assertSessionHasErrors('amount_cents');

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(500, $wallet->balance_cents);
        $this->assertSame(0, CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->count());
        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }

    public function test_admin_cannot_adjust_a_non_client_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        $attendant = User::factory()->attendant()->create();

        $this->actingAs($admin)
            ->post(route('admin.finance.wallets.adjust', $attendant), [
                'type' => LedgerEntryType::Credit->value,
                'amount_cents' => 1000,
                'reason' => 'Ajuste em perfil incorreto',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }
}
