<?php

namespace Tests\Feature;

use App\Models\AttendantProfile;
use App\Models\User;
use App\Support\AttendantDirectoryQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendantDirectoryQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_only_returns_published_profiles_of_active_users(): void
    {
        $published = AttendantProfile::factory()->online()->create([
            'display_name' => 'Ana Publicada',
        ]);

        AttendantProfile::factory()->create([
            'display_name' => 'Rascunho',
            'is_published' => false,
        ]);

        $inactiveUser = User::factory()->attendant()->create(['is_active' => false]);

        AttendantProfile::factory()->for($inactiveUser)->create([
            'display_name' => 'Inativo',
        ]);

        $profiles = app(AttendantDirectoryQuery::class)->query()->get();

        $this->assertCount(1, $profiles);
        $this->assertTrue($profiles->first()->is($published));
    }

    public function test_directory_selects_only_public_columns_and_loads_prices(): void
    {
        AttendantProfile::factory()->online()->create();

        $profile = app(AttendantDirectoryQuery::class)->query()->sole();
        $attributes = $profile->getAttributes();

        $this->assertArrayHasKey('display_name', $attributes);
        $this->assertArrayHasKey('availability', $attributes);
        $this->assertArrayNotHasKey('commission_rate_bps', $attributes);
        $this->assertArrayNotHasKey('last_seen_at', $attributes);
        $this->assertArrayNotHasKey('request_sound', $attributes);
        $this->assertTrue($profile->relationLoaded('modalityPrices'));
    }

    public function test_directory_orders_online_then_busy_then_offline(): void
    {
        AttendantProfile::factory()->offline()->create(['display_name' => 'Bruno Offline']);
        AttendantProfile::factory()->busy()->create(['display_name' => 'Ana Ocupada']);
        AttendantProfile::factory()->online()->create(['display_name' => 'Zulu Online']);

        $names = app(AttendantDirectoryQuery::class)->query()->pluck('display_name')->all();

        $this->assertSame(['Zulu Online', 'Ana Ocupada', 'Bruno Offline'], $names);
    }

    public function test_unpublished_profile_is_not_found_by_id(): void
    {
        $draft = AttendantProfile::factory()->create(['is_published' => false]);

        $this->expectException(ModelNotFoundException::class);

        app(AttendantDirectoryQuery::class)->findPublishedOrFail($draft->id);
    }
}
