<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\CreditLedgerEntry;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLedgerEntry>
 */
class CreditLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->numberBetween(100, 100000);

        return [
            'wallet_id' => Wallet::factory(),
            'type' => LedgerEntryType::Credit->value,
            'amount_cents' => $amount,
            'balance_after_cents' => $amount,
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
