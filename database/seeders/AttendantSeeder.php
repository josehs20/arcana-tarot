<?php

namespace Database\Seeders;

use App\Enums\AttendantAvailability;
use App\Enums\UserRole;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AttendantSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->ensureDemoEnvironment();

        foreach (DemoData::ATTENDANTS as $attendant) {
            $user = User::query()->firstOrCreate(
                ['email' => $attendant['email']],
                [
                    'name' => $attendant['name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make(DemoData::password()),
                    'role' => UserRole::Attendant->value,
                    'is_active' => true,
                ],
            );

            $availability = AttendantAvailability::from($attendant['availability']);

            $profile = AttendantProfile::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'display_name' => $attendant['display_name'],
                    'bio' => $attendant['bio'],
                    'photo_path' => null,
                    'specialties' => $attendant['specialties'],
                    'offered_modalities' => ['text', 'audio', 'video'],
                    'request_sound' => $attendant['request_sound'],
                    'commission_rate_bps' => $attendant['commission_rate_bps'],
                    'is_published' => true,
                    'availability' => $availability->value,
                    'last_seen_at' => $availability === AttendantAvailability::Online ? now() : now()->subDay(),
                ],
            );

            // Preserva taxa, biografia, nome e som editados depois da primeira
            // execução. Apenas o estado necessário ao cenário é reaplicado.
            $profile->forceFill([
                'is_published' => true,
                'availability' => $availability->value,
                'offered_modalities' => $profile->offered_modalities ?? ['text', 'audio', 'video'],
                'request_sound' => $profile->request_sound?->value ?? $attendant['request_sound'],
                'last_seen_at' => $availability === AttendantAvailability::Online ? now() : $profile->last_seen_at,
            ])->saveQuietly();

            $this->seedModalityPrices($profile);
        }
    }

    /**
     * Cada atendente recebe um preço por minuto para cada modalidade que
     * oferece. O preço é obrigatório para novas consultas, então o cenário de
     * demonstração nunca fica sem tarifa. Preços já ajustados manualmente são
     * preservados.
     */
    private function seedModalityPrices(AttendantProfile $profile): void
    {
        foreach ($profile->offeredModalities() as $modality) {
            AttendantModalityPrice::query()->firstOrCreate(
                [
                    'attendant_profile_id' => $profile->getKey(),
                    'modality' => $modality->value,
                ],
                [
                    'price_per_minute_cents' => DemoData::modalityPricePerMinuteCents($modality->value),
                ],
            );
        }
    }
}
