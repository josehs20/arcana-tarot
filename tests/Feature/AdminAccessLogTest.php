<?php

namespace Tests\Feature;

use App\Models\SiteVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Registro de acessos: coleta anônima e autenticada, heartbeat, exclusões
 * (painel administrativo e robôs), filtros da tela "Acessos" e expurgo.
 */
class AdminAccessLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Repete uma requisição como o mesmo navegador (cookie de visitante).
     */
    private function visitWithCookie(string $visitorId, string $path = '/'): TestResponse
    {
        return $this
            ->withCookie((string) config('access_log.cookie', 'arcana_visitor'), $visitorId)
            ->get($path);
    }

    public function test_guest_visit_is_recorded_with_random_cookie_and_hashed_ip(): void
    {
        $this->get('/')->assertOk();

        $visit = SiteVisit::query()->sole();

        $this->assertNull($visit->user_id);
        $this->assertNull($visit->ended_at);
        $this->assertSame('/', $visit->entry_path);
        $this->assertSame(1, $visit->page_views);
        $this->assertTrue(Str::isUuid($visit->visitor_id));
        $this->assertSame(64, strlen((string) $visit->ip_hash));
        $this->assertNotSame('127.0.0.1', $visit->ip_hash);
    }

    public function test_same_browser_keeps_a_single_visit_and_counts_page_views(): void
    {
        $this->get('/')->assertOk();
        $visitorId = SiteVisit::query()->sole()->visitor_id;

        $this->visitWithCookie($visitorId, '/como-funciona')->assertOk();

        $visit = SiteVisit::query()->sole();

        $this->assertSame(2, $visit->page_views);
        $this->assertSame('/', $visit->entry_path);
        $this->assertSame('/como-funciona', $visit->last_path);
    }

    public function test_visit_is_split_after_the_inactivity_window(): void
    {
        $visitorId = (string) Str::uuid();

        $previous = SiteVisit::factory()->create([
            'visitor_id' => $visitorId,
            'user_id' => null,
            'ended_at' => null,
            'started_at' => now()->subHours(3),
            'last_activity_at' => now()->subHours(2),
        ]);

        $this->visitWithCookie($visitorId)->assertOk();

        $previous->refresh();

        $this->assertNotNull($previous->ended_at);
        $this->assertTrue($previous->ended_at->equalTo($previous->last_activity_at));
        $this->assertSame(2, SiteVisit::query()->count());
        $this->assertSame(1, SiteVisit::query()->whereNull('ended_at')->count());
    }

    public function test_authenticated_visitor_is_linked_to_the_account(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/')->assertOk();

        $this->assertSame($client->id, SiteVisit::query()->sole()->user_id);
    }

    public function test_heartbeat_extends_the_open_visit_without_creating_another(): void
    {
        $visitorId = (string) Str::uuid();

        $visit = SiteVisit::factory()->active()->create([
            'visitor_id' => $visitorId,
            'started_at' => now()->subMinutes(10),
            'last_activity_at' => now()->subMinutes(6),
        ]);

        $this
            ->withCookie((string) config('access_log.cookie'), $visitorId)
            ->post(route('access.heartbeat'))
            ->assertNoContent();

        $this->assertTrue($visit->refresh()->last_activity_at->gte(now()->subMinute()));
        $this->assertSame(1, SiteVisit::query()->count());
    }

    public function test_heartbeat_ignores_unknown_or_expired_visits(): void
    {
        $visitorId = (string) Str::uuid();

        $stale = SiteVisit::factory()->active()->create([
            'visitor_id' => $visitorId,
            'started_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        $this
            ->withCookie((string) config('access_log.cookie'), $visitorId)
            ->post(route('access.heartbeat'))
            ->assertNoContent();

        $this
            ->withCookie((string) config('access_log.cookie'), (string) Str::uuid())
            ->post(route('access.heartbeat'))
            ->assertNoContent();

        $this->assertTrue($stale->refresh()->last_activity_at->lt(now()->subMinutes(30)));
        $this->assertSame(1, SiteVisit::query()->count());
    }

    public function test_bots_are_not_recorded(): void
    {
        $this
            ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/')
            ->assertOk();

        $this->assertDatabaseCount('site_visits', 0);
    }

    public function test_admin_panel_navigation_is_not_tracked(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertDatabaseCount('site_visits', 0);
    }

    public function test_only_admins_can_open_the_access_page(): void
    {
        $this->get(route('admin.access.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('admin.access.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_the_access_page_with_the_sidebar_entry(): void
    {
        $admin = $this->admin();

        $visit = SiteVisit::factory()->anonymous()->create([
            'started_at' => now()->subHours(2),
            'last_activity_at' => now()->subHour(),
            'ended_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.access.index'))
            ->assertOk()
            ->assertSee('Acessos ao site')
            ->assertSee(route('admin.access.index'), false)
            ->assertDontSee((string) $visit->ip_hash);
    }

    public function test_access_list_respects_period_audience_and_search_filters(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['name' => 'Marina Registrada']);

        SiteVisit::factory()->forUser($client)->create([
            'entry_path' => '/area-da-cliente',
            'started_at' => now()->subHours(2),
            'last_activity_at' => now()->subHour(),
            'ended_at' => now()->subHour(),
        ]);

        SiteVisit::factory()->anonymous()->create([
            'entry_path' => '/pagina-antiga',
            'last_path' => '/pagina-antiga',
            'started_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(40),
            'ended_at' => now()->subDays(40),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.access.index', ['audience' => 'registered']))
            ->assertOk()
            ->assertSee('Marina Registrada')
            ->assertDontSee('/pagina-antiga');

        $this->actingAs($admin)
            ->get(route('admin.access.index', ['audience' => 'anonymous']))
            ->assertOk()
            ->assertDontSee('Marina Registrada')
            ->assertDontSee('/pagina-antiga');

        $this->actingAs($admin)
            ->get(route('admin.access.index', ['q' => 'Marina']))
            ->assertOk()
            ->assertSee('Marina Registrada')
            ->assertDontSee('/pagina-antiga');

        $this->actingAs($admin)
            ->get(route('admin.access.index', ['period' => '30']))
            ->assertOk()
            ->assertDontSee('/pagina-antiga');
    }

    public function test_prune_command_closes_abandoned_visits_and_removes_expired_history(): void
    {
        config([
            'access_log.inactivity_minutes' => 30,
            'access_log.retention_days' => 90,
        ]);

        $abandoned = SiteVisit::factory()->create([
            'ended_at' => null,
            'started_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(1),
        ]);

        $current = SiteVisit::factory()->active()->create();

        $expired = SiteVisit::factory()->create([
            'started_at' => now()->subDays(120),
            'last_activity_at' => now()->subDays(120),
            'ended_at' => now()->subDays(120),
        ]);

        $this->artisan('arcana:prune-site-visits')->assertSuccessful();

        $abandoned->refresh();

        $this->assertNotNull($abandoned->ended_at);
        $this->assertTrue($abandoned->ended_at->equalTo($abandoned->last_activity_at));
        $this->assertNull($current->refresh()->ended_at);
        $this->assertDatabaseMissing('site_visits', ['id' => $expired->id]);
    }
}
