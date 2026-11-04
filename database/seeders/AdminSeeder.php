<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->ensureDemoEnvironment();

        User::query()->firstOrCreate(
            ['email' => DemoData::ADMIN_EMAIL],
            [
                'name' => 'Administração Arcana',
                'email_verified_at' => now(),
                'password' => Hash::make(DemoData::password()),
                'role' => UserRole::Admin->value,
                'is_active' => true,
            ],
        );
    }
}
