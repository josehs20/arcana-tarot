<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\CreditWalletManager;
use Illuminate\Support\Facades\Hash;

class ClientSeeder extends DemoSeeder
{
    public function run(CreditWalletManager $wallets): void
    {
        $this->ensureDemoEnvironment();

        foreach (DemoData::CLIENTS as $client) {
            $user = User::query()->firstOrCreate(
                ['email' => $client['email']],
                [
                    'name' => $client['name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make(DemoData::password()),
                    'role' => UserRole::Client->value,
                    'is_active' => true,
                ],
            );

            $wallets->walletFor($user);
        }
    }
}
