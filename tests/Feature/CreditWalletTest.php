<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CreditWalletManager;
use App\Support\InsufficientCreditBalanceException;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CreditWalletTest extends TestCase
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

    public function test_credit_updates_projection_and_is_idempotent(): void
    {
        $client = User::factory()->create();
        $manager = $this->manager();

        $first = $this->credit($client, 1500, 'test-credit:1');
        $second = $this->credit($client, 1500, 'test-credit:1');

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertTrue($first->is($second));
        $this->assertSame(1500, $wallet->balance_cents);
        $this->assertSame(1500, $manager->ledgerBalance($wallet));
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(LedgerEntryType::Credit, $first->type);
        $this->assertSame(1500, $first->balance_after_cents);
        $this->assertSame(AuditLog::class, $first->reference_type);
        $this->assertNotNull($first->reference_id);
    }

    public function test_debit_reduces_balance_and_is_idempotent(): void
    {
        $client = User::factory()->create();
        $manager = $this->manager();

        $this->credit($client, 1000, 'test-credit:2');
        $first = $this->debit($client, 400, 'test-debit:2');
        $second = $this->debit($client, 400, 'test-debit:2');

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertTrue($first->is($second));
        $this->assertSame(600, $wallet->balance_cents);
        $this->assertSame(600, $manager->ledgerBalance($wallet));
        $this->assertSame(2, CreditLedgerEntry::query()->count());
        $this->assertSame(LedgerEntryType::Debit, $first->type);
        $this->assertSame(600, $first->balance_after_cents);
    }

    public function test_insufficient_balance_is_rejected_without_writing_an_entry(): void
    {
        $client = User::factory()->create();

        $this->credit($client, 900, 'test-credit:3');

        try {
            $this->debit($client, 1000, 'test-debit:3');
            $this->fail('O débito acima do saldo deveria ter sido recusado.');
        } catch (InsufficientCreditBalanceException $exception) {
            $this->assertSame(1000, $exception->requestedCents);
            $this->assertSame(900, $exception->availableCents);
        }

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(900, $wallet->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(0, CreditLedgerEntry::query()->where('type', LedgerEntryType::Debit->value)->count());
    }

    /**
     * Duas tentativas de débito sobre o mesmo saldo não podem deixá-lo
     * negativo. O manager bloqueia a linha e o UPDATE condicional é a segunda
     * barreira, inclusive em bancos de teste onde o lock é no-op.
     */
    public function test_two_debit_attempts_on_the_same_balance_cannot_overdraw(): void
    {
        $client = User::factory()->create();
        $manager = $this->manager();

        $this->credit($client, 1000, 'test-credit:4');
        $this->debit($client, 600, 'test-debit:4a');

        try {
            $this->debit($client, 600, 'test-debit:4b');
            $this->fail('O segundo débito deveria ter sido recusado.');
        } catch (InsufficientCreditBalanceException) {
            // Esperado: o saldo restante é menor que o novo débito.
        }

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(400, $wallet->balance_cents);
        $this->assertSame(400, $manager->ledgerBalance($wallet));
        $this->assertSame(1, CreditLedgerEntry::query()->where('type', LedgerEntryType::Debit->value)->count());
        $this->assertSame(2, CreditLedgerEntry::query()->count());
    }

    public function test_idempotency_key_cannot_be_reused_with_different_parameters(): void
    {
        $client = User::factory()->create();

        $this->credit($client, 1000, 'test-shared-key');

        try {
            $this->debit($client, 1000, 'test-shared-key');
            $this->fail('A reutilização da chave com outro tipo deveria ser recusada.');
        } catch (DomainException) {
            // Esperado: a chave pertence a um crédito de 1000 centavos.
        }

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(1000, $wallet->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }

    public function test_wallet_projection_matches_the_ledger(): void
    {
        $client = User::factory()->create();
        $manager = $this->manager();

        $this->credit($client, 500, 'test-projection:credit-1');
        $this->credit($client, 300, 'test-projection:credit-2');
        $this->debit($client, 200, 'test-projection:debit');

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();

        $credits = (int) CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', LedgerEntryType::Credit->value)
            ->sum('amount_cents');

        $debits = (int) CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', LedgerEntryType::Debit->value)
            ->sum('amount_cents');

        $this->assertSame(800, $credits);
        $this->assertSame(200, $debits);
        $this->assertSame($credits - $debits, $wallet->balance_cents);
        $this->assertSame($wallet->balance_cents, $manager->ledgerBalance($wallet));
    }

    public function test_admin_adjustment_records_author_reason_and_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $manager = $this->manager();

        $entry = $manager->adjust(
            target: $client,
            actor: $admin,
            type: LedgerEntryType::Credit,
            amountCents: 2500,
            reason: 'Crédito de cortesia aprovado',
            idempotencyKey: 'admin_adjustment:'.$client->id.':operation-1',
        );

        $wallet = Wallet::query()->where('user_id', $client->id)->firstOrFail();
        $audit = AuditLog::query()->where('action', 'wallet.adjustment')->firstOrFail();

        $this->assertSame(2500, $wallet->balance_cents);
        $this->assertSame(2500, $entry->amount_cents);
        $this->assertSame('Crédito de cortesia aprovado', $entry->reason);
        $this->assertSame($admin->id, $entry->created_by);
        $this->assertSame(AuditLog::class, $entry->reference_type);
        $this->assertSame($audit->id, $entry->reference_id);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($wallet->id, $audit->subject_id);
        $this->assertSame('Crédito de cortesia aprovado', $audit->metadata['reason']);
        $this->assertSame(2500, $audit->metadata['amount_cents']);
    }

    public function test_admin_adjustment_is_idempotent_and_does_not_duplicate_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $manager = $this->manager();

        $first = $manager->adjust($client, $admin, LedgerEntryType::Credit, 1200, 'Ajuste idempotente', 'adjust-key-1');
        $second = $manager->adjust($client, $admin, LedgerEntryType::Credit, 1200, 'Ajuste idempotente', 'adjust-key-1');

        $this->assertTrue($first->is($second));
        $this->assertSame(1200, $client->wallet->fresh()->balance_cents);
        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'wallet.adjustment')->count());
    }

    public function test_only_an_active_admin_can_perform_an_adjustment(): void
    {
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
        $client = User::factory()->create();

        $this->expectException(DomainException::class);

        $this->manager()->adjust(
            $client,
            $inactiveAdmin,
            LedgerEntryType::Credit,
            1000,
            'Tentativa sem autorização',
            'adjust-unauthorized',
        );
    }

    public function test_ledger_entries_cannot_be_updated(): void
    {
        $client = User::factory()->create();
        $entry = $this->credit($client, 1000, 'test-immutable:update');

        try {
            $entry->amount_cents = 9999;
            $entry->save();
            $this->fail('Um lançamento do livro-razão não deveria ser atualizado.');
        } catch (LogicException) {
            // Esperado: lançamentos são imutáveis.
        }

        $this->assertSame(1000, $entry->fresh()->amount_cents);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $client = User::factory()->create();
        $entry = $this->credit($client, 1000, 'test-immutable:delete');

        try {
            $entry->delete();
            $this->fail('Um lançamento do livro-razão não deveria ser excluído.');
        } catch (LogicException) {
            // Esperado: lançamentos são imutáveis.
        }

        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }
}
