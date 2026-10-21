<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('autocomplete="new-password"', false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Recuperar senha');

        $this->get(route('password.reset', ['token' => 'token-de-teste', 'email' => 'cliente@example.com']))
            ->assertOk()
            ->assertSee('Redefinir senha')
            ->assertSee('token-de-teste', false);
    }

    public function test_each_role_sees_its_own_panel_layout(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Painel do Cliente')
            ->assertSee('sidebarClient', false)
            ->assertSee('navbar-arcana', false);

        $attendant = User::factory()->attendant()->create();

        $this->actingAs($attendant)->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('Painel do Atendente')
            ->assertSee('sidebarClient', false)
            ->assertSee('Online');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Painel do Administrador')
            ->assertSee('sidebarAdmin', false);
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
            ->assertSee('type="button"', false);

        $this->blade('<x-ui.alert type="success">Tudo certo</x-ui.alert>')
            ->assertSee('alert-success', false)
            ->assertSee('Tudo certo');

        $this->blade('<x-ui.badge variant="gold">Destaque</x-ui.badge>')
            ->assertSee('badge-gold', false);

        $this->blade('<x-ui.card class="card-gilded"><x-ui.card-body>Conteúdo</x-ui.card-body></x-ui.card>')
            ->assertSee('card card-gilded', false)
            ->assertSee('card-body', false);
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
