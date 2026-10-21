<?php

namespace Database\Factories;

use App\Enums\CommissionEntryType;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionLedgerEntry>
 */
class CommissionLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        $base = fake()->numberBetween(100, 100000);

        return [
            'attendant_id' => User::factory()->attendant(),
            'consultation_id' => Consultation::factory(),
            'commission_rate_bps' => 3000,
            'base_amount_cents' => $base,
            'commission_amount_cents' => (int) round($base * 3000 / 10000),
            'type' => CommissionEntryType::Accrual->value,
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
