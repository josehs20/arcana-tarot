<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\CreditWalletManager;
use Carbon\Carbon;

class WalletSeeder extends DemoSeeder
{
    public function run(CreditWalletManager $wallets): void
    {
        $this->ensureDemoEnvironment();

        $payments = Payment::query()
            ->where('provider', 'pix_sandbox')
            ->where('idempotency_key', 'like', 'demo:pix:%')
            ->where('status', PaymentStatus::Confirmed->value)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            Carbon::setTestNow($payment->paid_at ?? $payment->created_at);

            try {
                $wallets->credit(
                    user: $payment->user,
                    amountCents: $payment->amount_cents,
                    idempotencyKey: 'demo:wallet:'.$payment->idempotency_key,
                    reference: $payment,
                );
            } finally {
                Carbon::setTestNow();
            }
        }
    }
}
