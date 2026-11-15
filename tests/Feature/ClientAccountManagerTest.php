<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\ClientAccountManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientAccountManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_active_client_with_an_empty_wallet(): void
    {
        $user = app(ClientAccountManager::class)->register([
            'name' => 'Cliente Novo',
            'email' => 'cliente.novo@example.com',
            'phone' => '11999999999',
            'birth_date' => '1990-05-20',
            'gender' => Gender::Female->value,
            'password' => 'senha-segura',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->exists);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('senha-segura', $user->password));

        $wallet = $user->wallet()->sole();

        $this->assertSame(0, $wallet->balance_cents);
        $this->assertSame(0, $wallet->ledgerEntries()->count());

        $this->assertDatabaseHas('users', [
            'email' => 'cliente.novo@example.com',
            'role' => UserRole::Client->value,
        ]);
    }
}
