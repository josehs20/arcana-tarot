<?php

namespace Database\Seeders;

use App\Enums\AttendantAvailability;
use App\Enums\UserRole;
use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@arcana.local'],
            ['name' => 'Administrador', 'role' => UserRole::Admin->value, 'password' => bcrypt('password')],
        );

        $attendant = User::firstOrCreate(
            ['email' => 'atendente@arcana.local'],
            ['name' => 'Atendente Exemplo', 'role' => UserRole::Attendant->value, 'password' => bcrypt('password')],
        );

        AttendantProfile::firstOrCreate(
            ['user_id' => $attendant->id],
            [
                'display_name' => 'Atendente Exemplo',
                'bio' => 'Especialista em Tarot com anos de experiência.',
                'specialties' => ['Amor', 'Carreira', 'Autoconhecimento'],
                'commission_rate_bps' => 3000,
                'is_published' => true,
                'availability' => AttendantAvailability::Online->value,
            ],
        );

        User::firstOrCreate(
            ['email' => 'cliente@arcana.local'],
            ['name' => 'Cliente Exemplo', 'role' => UserRole::Client->value, 'password' => bcrypt('password')],
        );

        $deck = TarotDeck::firstOrCreate(
            ['slug' => 'rider-waite'],
            ['name' => 'Rider-Waite', 'description' => 'Baralho clássico de demonstração', 'is_active' => true],
        );

        if ($deck->cards()->count() === 0) {
            TarotCard::factory()->count(3)->create(['deck_id' => $deck->id]);
        }

        AttendantApplication::factory()->create([
            'name' => 'Candidato Exemplo',
            'email' => 'candidato@example.com',
        ]);

        Setting::firstOrCreate(['key' => 'credit_unit_price_cents'], ['value' => '100', 'type' => 'integer']);
        Setting::firstOrCreate(['key' => 'rate_credits_per_minute'], ['value' => '1', 'type' => 'integer']);
    }
}
