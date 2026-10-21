<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        foreach (['/admin', '/attendant', '/client'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_client_cannot_access_admin_or_attendant_area(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/admin')->assertForbidden();
        $this->actingAs($client)->get('/attendant')->assertForbidden();
        $this->actingAs($client)->get('/client')->assertOk();
    }

    public function test_attendant_cannot_access_admin_or_client_area(): void
    {
        $attendant = User::factory()->attendant()->create();

        $this->actingAs($attendant)->get('/admin')->assertForbidden();
        $this->actingAs($attendant)->get('/client')->assertForbidden();
        $this->actingAs($attendant)->get('/attendant')->assertOk();
    }

    public function test_admin_can_access_admin_area_only(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/attendant')->assertForbidden();
        $this->actingAs($admin)->get('/client')->assertForbidden();
    }

    public function test_inactive_user_is_forbidden(): void
    {
        $client = User::factory()->create(['is_active' => false]);

        $this->actingAs($client)->get('/client')->assertForbidden();
    }
}
