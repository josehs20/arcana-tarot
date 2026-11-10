<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteAppearanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array<string, string>>  $colors
     * @param  array<string, string>  $fonts
     * @return array{colors: array<string, array<string, string>>, fonts: array<string, string>}
     */
    private function payload(array $colors = [], array $fonts = []): array
    {
        return [
            'colors' => array_replace_recursive(
                (array) config('appearance.defaults'),
                $colors,
            ),
            'fonts' => array_merge([
                'display' => 'cormorant',
                'body' => 'inter',
            ], $fonts),
        ];
    }

    public function test_admin_can_view_the_appearance_editor(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.site.appearance.edit'))
            ->assertOk()
            ->assertSee('Cores e fontes');
    }

    public function test_default_appearance_does_not_inject_custom_styles(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="arcana-appearance"', false);
    }

    public function test_admin_can_save_custom_colors_and_fonts_and_the_public_site_reflects_them(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.appearance.update'), $this->payload(
                ['light' => ['gold' => '#123456'], 'dark' => ['bg' => '#010203']],
                ['display' => 'georgia'],
            ))
            ->assertRedirect(route('admin.site.appearance.edit'))
            ->assertSessionHas('success');

        $stored = json_decode((string) Setting::query()->where('key', 'appearance')->value('value'), true);

        $this->assertSame('#123456', $stored['colors']['light']['gold']);
        $this->assertSame('#010203', $stored['colors']['dark']['bg']);
        $this->assertSame('georgia', $stored['fonts']['display']);

        $this->assertSame(1, AuditLog::query()->where('action', 'site.appearance_updated')->count());

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="arcana-appearance"', false)
            ->assertSee('--arcana-gold:#123456', false)
            ->assertSee('--arcana-bg:#010203', false)
            ->assertSee('--arcana-font-display:Georgia', false);
    }

    public function test_appearance_validation_rejects_invalid_hex_and_unknown_fonts(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.site.appearance.edit'))
            ->patch(route('admin.site.appearance.update'), $this->payload(
                ['light' => ['gold' => 'nope']],
                ['display' => 'comic-sans'],
            ))
            ->assertRedirect(route('admin.site.appearance.edit'))
            ->assertSessionHasErrors(['colors.light.gold', 'fonts.display']);

        $this->assertDatabaseMissing('settings', ['key' => 'appearance']);
    }

    public function test_non_admins_cannot_manage_appearance(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get(route('admin.site.appearance.edit'))->assertForbidden();
        $this->actingAs($client)->patch(route('admin.site.appearance.update'), $this->payload())->assertForbidden();
        $this->actingAs($client)->delete(route('admin.site.appearance.reset'))->assertForbidden();
    }

    public function test_admin_can_reset_appearance_to_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.site.appearance.update'), $this->payload(
            ['light' => ['gold' => '#123456']],
        ));

        $this->assertDatabaseHas('settings', ['key' => 'appearance']);

        $this->actingAs($admin)
            ->delete(route('admin.site.appearance.reset'))
            ->assertRedirect(route('admin.site.appearance.edit'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('settings', ['key' => 'appearance']);
        $this->assertSame(1, AuditLog::query()->where('action', 'site.appearance_reset')->count());

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="arcana-appearance"', false);
    }
}
