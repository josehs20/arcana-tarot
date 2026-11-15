<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_a_bearer_token(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer token-invalido')
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_me_returns_the_authenticated_user_identity(): void
    {
        $user = User::factory()->create([
            'name' => 'Cliente API',
            'email' => 'api@example.com',
            'phone' => '11999999999',
            'birth_date' => '1990-05-20',
            'gender' => Gender::Female,
            'role' => UserRole::Client,
        ]);

        $token = $user->createToken('flutter', ['client'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Cliente API')
            ->assertJsonPath('data.email', 'api@example.com')
            ->assertJsonPath('data.phone', '11999999999')
            ->assertJsonPath('data.birth_date', '1990-05-20')
            ->assertJsonPath('data.gender', Gender::Female->value)
            ->assertJsonPath('data.role', UserRole::Client->value)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_token_is_stored_hashed_and_revocation_is_immediate(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('flutter', ['client']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $newToken->plainTextToken,
        ]);

        $this->assertNotNull($newToken->accessToken->expires_at);

        $this->assertSame(
            hash('sha256', explode('|', $newToken->plainTextToken, 2)[1]),
            DB::table('personal_access_tokens')->where('id', $newToken->accessToken->id)->value('token'),
        );

        $newToken->accessToken->delete();

        $this->withHeader('Authorization', 'Bearer '.$newToken->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        config(['sanctum.expiration' => 1]);

        $user = User::factory()->create();
        $token = $user->createToken('flutter', ['client'])->plainTextToken;

        $this->travel(2)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_prune_command_removes_expired_tokens(): void
    {
        config(['sanctum.expiration' => 1]);

        $user = User::factory()->create();
        $user->createToken('flutter', ['client']);

        $this->travel(2)->minutes();

        $this->artisan('sanctum:prune-expired', ['--hours' => 0])
            ->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_is_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $token = $user->createToken('flutter', ['client'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Conta inativa.');
    }

    public function test_web_session_does_not_authenticate_the_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertAuthenticatedAs($user);
        $this->get(route('client.history'))->assertOk();
    }

    public function test_api_rate_limit_is_enforced_per_user(): void
    {
        config(['api.rate_limit_per_minute' => 2]);

        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('flutter', ['client'])->plainTextToken];

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/me')->assertTooManyRequests();
    }

    public function test_unknown_api_route_returns_json_not_found(): void
    {
        $this->getJson('/api/v1/rota-inexistente')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
