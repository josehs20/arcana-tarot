<?php

namespace Tests\Feature;

use App\Enums\ThemePreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_start_on_the_light_theme_without_persisted_preference(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-theme="light"', false)
            ->assertSee('data-theme-user="0"', false)
            ->assertSee('name="theme-endpoint"', false)
            ->assertSee('data-theme-toggle', false);
    }

    public function test_theme_is_rendered_from_the_authenticated_user_preference(): void
    {
        $client = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

        $this->actingAs($client)->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertSee('data-theme-user="1"', false);

        $defaultClient = User::factory()->create();

        $this->actingAs($defaultClient)->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('data-theme="light"', false)
            ->assertSee('data-theme-user="1"', false);
    }

    public function test_authenticated_user_can_persist_the_dark_theme(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->patchJson(route('preferences.theme.update'), ['theme' => ThemePreference::Dark->value])
            ->assertOk()
            ->assertJson(['theme' => ThemePreference::Dark->value]);

        $this->assertSame(ThemePreference::Dark, $client->fresh()->theme_preference);
    }

    public function test_authenticated_user_can_switch_back_to_the_light_theme(): void
    {
        $client = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

        $this->actingAs($client)
            ->patch(route('preferences.theme.update'), ['theme' => ThemePreference::Light->value])
            ->assertRedirect();

        $this->assertSame(ThemePreference::Light, $client->fresh()->theme_preference);
    }

    public function test_unknown_theme_is_rejected(): void
    {
        $client = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

        $this->actingAs($client)
            ->patchJson(route('preferences.theme.update'), ['theme' => 'neon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme');

        $this->assertSame(ThemePreference::Dark, $client->fresh()->theme_preference);
    }

    public function test_guests_cannot_persist_a_theme_preference(): void
    {
        $this->patchJson(route('preferences.theme.update'), ['theme' => ThemePreference::Dark->value])
            ->assertUnauthorized();
    }
}
