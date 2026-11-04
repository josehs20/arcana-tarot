<?php

namespace Database\Seeders;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use App\Support\CommissionManager;

class CommissionSeeder extends DemoSeeder
{
    public function run(CommissionManager $commissions): void
    {
        $this->ensureDemoEnvironment();

        $clientIds = User::query()
            ->whereIn('email', array_column(DemoData::CLIENTS, 'email'))
            ->pluck('id');

        $consultations = Consultation::query()
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', [
                ConsultationStatus::Ended->value,
                ConsultationStatus::Interrupted->value,
            ])
            ->orderBy('id')
            ->get();

        foreach ($consultations as $consultation) {
            $commissions->accrue($consultation);
        }
    }
}
