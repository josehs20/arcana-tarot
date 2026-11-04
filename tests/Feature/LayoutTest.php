<?php

namespace Tests\Feature;

use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_renders_with_the_public_layout(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Arcana Tarot')
            ->assertSee('navbar-arcana', false)
            ->assertSee('footer-arcana', false)
            ->assertSee('container-wide', false)
            ->assertSee('conteudo-principal', false);
    }

    public function test_home_links_authenticated_users_to_their_own_panel(): void
    {
        $attendant = User::factory()->attendant()->make();

        $this->actingAs($attendant)->get('/')
            ->assertOk()
            ->assertSee(route('attendant.dashboard'), false)
            ->assertDontSee(route('client.dashboard'), false);

        $admin = User::factory()->admin()->make();

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertSee(route('admin.dashboard'), false)
            ->assertDontSee(route('client.dashboard'), false);
    }

    public function test_guest_auth_screens_use_the_guest_layout(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('auth-card', false)
            ->assertSee('Entrar')
            ->assertSee('autocomplete="email"', false)
            ->assertSee('name="csrf-token"', false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Criar conta')
            ->assertSee('name="phone"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('autocomplete="new-password"', false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Recuperar senha');

        $this->get(route('password.reset', ['token' => 'token-de-teste', 'email' => 'cliente@example.com']))
            ->assertOk()
            ->assertSee('Redefinir senha')
            ->assertSee('token-de-teste', false);
    }

    public function test_panel_layout_surfaces_session_flash_messages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['success' => 'Seção atualizada. O site público já exibe o novo conteúdo.'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('flash-region', false)
            ->assertSee('alert alert-success alert-dismissible mb-3', false)
            ->assertDontSee('alert-dismissible fade', false)
            ->assertSee('Seção atualizada. O site público já exibe o novo conteúdo.');
    }

    public function test_flash_partial_lists_validation_errors_as_an_alert(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['title' => ['Informe o título da seção.']]));

        $this->blade('@include("layouts.partials.flash-messages")', ['errors' => $errors])
            ->assertSee('alert alert-danger alert-dismissible mb-3', false)
            ->assertSee('Informe o título da seção.');
    }

    public function test_flash_partial_can_skip_validation_errors_when_the_page_renders_them(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['title' => ['Informe o título da seção.']]));

        $this->blade('@include("layouts.partials.flash-messages", ["showErrors" => false])', ['errors' => $errors])
            ->assertDontSee('alert-danger', false);
    }

    public function test_public_layout_surfaces_session_flash_messages(): void
    {
        $this->withSession(['status' => 'Link de redefinição de senha enviado.'])
            ->get(route('contact'))
            ->assertOk()
            ->assertSee('container-wide pt-4', false)
            ->assertSee('alert alert-success alert-dismissible mb-3', false)
            ->assertSee('Link de redefinição de senha enviado.');

        $this->withSession(['error' => 'Não foi possível concluir a ação.'])
            ->get(route('work-with-us.index'))
            ->assertOk()
            ->assertSee('alert alert-danger alert-dismissible mb-3', false)
            ->assertSee('Não foi possível concluir a ação.');
    }

    public function test_flash_messages_leave_no_empty_wrapper_behind(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertDontSee('flash-region', false)
            ->assertDontSee('container-wide pt-4', false);
    }

    public function test_each_role_sees_its_own_panel_layout(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Painel do Cliente')
            ->assertSee('sidebarClient', false)
            ->assertSee('navbar-arcana', false)
            ->assertSee('panel-shell', false)
            ->assertSee('panel-sidebar', false)
            ->assertSee('panel-nav__link', false)
            ->assertSee('stat-card', false);

        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        $this->actingAs($attendant)->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('Painel do Atendente')
            ->assertSee('sidebarClient', false)
            ->assertSee('Online')
            ->assertSee('panel-shell', false)
            ->assertSee('stat-card', false);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Painel do Administrador')
            ->assertSee('sidebarAdmin', false)
            ->assertSee('panel-shell', false)
            ->assertSee('stat-card', false);
    }

    public function test_panel_sidebar_exposes_role_specific_navigation(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('data-bs-target="#sidebarClient"', false)
            ->assertSee(route('client.credits.index'), false)
            ->assertSee(route('attendants.index'), false)
            ->assertSee('panel-nav__link is-active', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-panel-sidebar-toggle', false)
            ->assertSee('aria-controls="sidebarClient"', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('data-label="Carteira e créditos"', false)
            ->assertSee('data-label="Sair da conta"', false)
            ->assertDontSee('sidebarAdmin', false);

        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        $this->actingAs($attendant)->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('Área do atendente')
            ->assertSee(route('attendant.history'), false)
            ->assertDontSee(route('client.credits.index'), false);

        AttendantApplication::factory()->create();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-bs-target="#sidebarAdmin"', false)
            ->assertSee('Visão geral')
            ->assertSee('Gestão')
            ->assertSee('Financeiro')
            ->assertSee('Sistema')
            ->assertSee(route('admin.applications.index'), false)
            ->assertSee(route('admin.site.index'), false)
            ->assertSee('1 candidatura pendente')
            ->assertSee('aria-controls="sidebarAdmin"', false)
            ->assertSee('data-label="Candidaturas"', false)
            ->assertSee('arcana.panel.sidebar', false)
            ->assertDontSee('sidebarClient', false);
    }

    public function test_status_badge_always_exposes_visible_text(): void
    {
        $this->blade('<x-ui.status-badge status="busy" />')
            ->assertSee('Ocupado')
            ->assertSee('status-dot--busy', false);

        $this->blade('<x-ui.status-badge status="offline" :dot="false" />')
            ->assertSee('Offline')
            ->assertDontSee('status-dot', false);

        $this->blade('<x-ui.status-badge status="under_review" />')
            ->assertSee('Em análise');
    }

    public function test_reusable_ui_components_render_expected_classes(): void
    {
        $this->blade('<x-ui.button variant="ghost" size="lg" block>Continuar</x-ui.button>')
            ->assertSee('btn-ghost', false)
            ->assertSee('btn-lg', false)
            ->assertSee('w-100', false)
            ->assertSee('type="button"', false)
            ->assertSee('</button>', false)
            ->assertSee('Continuar');

        $this->blade('<x-ui.alert type="success">Tudo certo</x-ui.alert>')
            ->assertSee('alert-success', false)
            ->assertSee('alert-dismissible', false)
            ->assertDontSee('fade', false)
            ->assertSee('Tudo certo');

        $this->blade('<x-ui.badge variant="gold">Destaque</x-ui.badge>')
            ->assertSee('badge-gold', false);

        $this->blade('<x-ui.card class="card-gilded"><x-ui.card-body>Conteúdo</x-ui.card-body></x-ui.card>')
            ->assertSee('card card-gilded', false)
            ->assertSee('card-body', false);

        $this->blade('<x-ui.stat-card label="Saldo" value="R$ 10,00" hint="Disponível" icon="wallet" tone="gold" href="/carteira" />')
            ->assertSee('stat-card stat-card--gold', false)
            ->assertSee('stat-card__label', false)
            ->assertSee('stat-card__icon', false)
            ->assertSee('stat-card__hint', false)
            ->assertSee('stat-card__cta', false);

        $this->blade('<x-ui.chart :labels="[\'01/10\']" :values="[0]" series-label="Consultas" period="Últimos 30 dias" />')
            ->assertSee('chart-empty', false)
            ->assertSee('Sem dados no período')
            ->assertSee('Últimos 30 dias');

        $this->blade('<x-ui.chart :labels="[\'01/10\', \'02/10\']" :values="[2, 5]" series-label="Consultas" type="bar" />')
            ->assertSee('chart__svg', false)
            ->assertSee('chart__bar', false)
            ->assertSee('Consultas: 7 no total', false);
    }

    public function test_themed_error_pages_render(): void
    {
        $this->get('/pagina-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertSee('error-page', false);

        $client = User::factory()->create();

        $this->actingAs($client)->get(route('admin.dashboard'))
            ->assertForbidden()
            ->assertSee('Acesso negado')
            ->assertSee('403');
    }
}
