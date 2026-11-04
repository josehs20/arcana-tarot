<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Pagamentos fictícios do provedor `pix_sandbox`.
 *
 * Nenhum webhook é disparado e nenhuma cobrança real é criada. Os registros
 * confirmados existem apenas para dar origem rastreável aos créditos iniciais
 * do ambiente de demonstração.
 */
class PaymentSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $unit = DemoData::creditUnitPriceCents();
        $minimumCredits = max(1, DemoData::rateCreditsPerMinute() * DemoData::minimumBalanceMinutes());
        $now = now();

        $this->createPayment(
            key: 'free-confirmed',
            user: DemoData::user(DemoData::CLIENTS['free']['email']),
            credits: 50,
            status: PaymentStatus::Confirmed,
            createdAt: $now->copy()->subDays(5),
            paidAt: $now->copy()->subDays(5),
            expiresAt: $now->copy()->subDays(5),
            unit: $unit,
        );

        $this->createPayment(
            key: 'history-confirmed',
            user: DemoData::user(DemoData::CLIENTS['history']['email']),
            credits: 40,
            status: PaymentStatus::Confirmed,
            createdAt: $now->copy()->subDays(4),
            paidAt: $now->copy()->subDays(4),
            expiresAt: $now->copy()->subDays(4),
            unit: $unit,
        );

        $this->createPayment(
            key: 'pending-client-confirmed',
            user: DemoData::user(DemoData::CLIENTS['pending']['email']),
            credits: 20,
            status: PaymentStatus::Confirmed,
            createdAt: $now->copy()->subDays(3),
            paidAt: $now->copy()->subDays(3),
            expiresAt: $now->copy()->subDays(3),
            unit: $unit,
        );

        $this->createPayment(
            key: 'active-client-confirmed',
            user: DemoData::user(DemoData::CLIENTS['active']['email']),
            credits: 20,
            status: PaymentStatus::Confirmed,
            createdAt: $now->copy()->subHours(2),
            paidAt: $now->copy()->subHours(2),
            expiresAt: $now->copy()->subHours(2),
            unit: $unit,
        );

        $this->createPayment(
            key: 'no-balance-confirmed',
            user: DemoData::user(DemoData::CLIENTS['no_balance']['email']),
            credits: $minimumCredits,
            status: PaymentStatus::Confirmed,
            createdAt: $now->copy()->subDays(2),
            paidAt: $now->copy()->subDays(2),
            expiresAt: $now->copy()->subDays(2),
            unit: $unit,
        );

        $this->createPayment(
            key: 'free-pending',
            user: DemoData::user(DemoData::CLIENTS['free']['email']),
            credits: 10,
            status: PaymentStatus::Pending,
            createdAt: $now->copy()->subMinutes(30),
            paidAt: null,
            expiresAt: $now->copy()->addMinutes(30),
            unit: $unit,
        );

        $this->createPayment(
            key: 'history-expired',
            user: DemoData::user(DemoData::CLIENTS['history']['email']),
            credits: 5,
            status: PaymentStatus::Expired,
            createdAt: $now->copy()->subDays(6),
            paidAt: null,
            expiresAt: $now->copy()->subDays(5),
            unit: $unit,
        );
    }

    private function createPayment(
        string $key,
        User $user,
        int $credits,
        PaymentStatus $status,
        CarbonInterface $createdAt,
        ?CarbonInterface $paidAt,
        ?CarbonInterface $expiresAt,
        int $unit,
    ): Payment {
        $amount = $credits * $unit;
        $rawStatus = match ($status) {
            PaymentStatus::Confirmed => 'demo_confirmed',
            PaymentStatus::Pending => 'demo_waiting_payment',
            PaymentStatus::Expired => 'demo_expired',
            default => 'demo_'.$status->value,
        };

        Carbon::setTestNow($createdAt);

        try {
            return Payment::query()->firstOrCreate(
                ['idempotency_key' => 'demo:pix:'.$key],
                [
                    'user_id' => $user->getKey(),
                    'provider' => 'pix_sandbox',
                    'provider_payment_id' => 'sandbox_demo_'.$key,
                    'external_reference' => 'demo_recharge_'.$key,
                    'amount_cents' => $amount,
                    'credits' => $credits,
                    'credit_unit_price_cents' => $unit,
                    'status' => $status->value,
                    'raw_status' => $rawStatus,
                    'pix_copy_paste' => 'DADOS-DE-DEMONSTRACAO-SEM-PIX-REAL|REF=demo_recharge_'.$key,
                    'failure_reason' => $status === PaymentStatus::Expired ? 'demo_expired' : null,
                    'expires_at' => $expiresAt,
                    'paid_at' => $paidAt,
                ],
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
