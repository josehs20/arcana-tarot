<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\BulkEmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A identidade exibida no sistema acompanha o campo "Nome do site" do CMS
 * (/admin/conteudo). Nenhuma tela deve manter o nome antigo fixo no código.
 */
class SiteNameBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function renameSite(string $name = 'Astros21'): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'site_name'],
            ['value' => $name, 'type' => 'string'],
        );
    }

    public function test_guest_screens_use_the_cms_site_name_in_the_brand_and_title(): void
    {
        $this->renameSite();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<title>Entrar | Astros21</title>', false)
            ->assertSee('<span>Astros21</span>', false)
            ->assertDontSee('Arcana Tarot');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('<title>Criar conta | Astros21</title>', false);
    }

    public function test_panel_titles_use_the_cms_site_name(): void
    {
        $this->renameSite();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<title>Painel Administrativo | Astros21</title>', false)
            ->assertDontSee('Arcana Tarot');
    }

    public function test_public_pages_use_the_cms_site_name_in_titles_and_descriptions(): void
    {
        $this->renameSite();

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('<title>Atendentes — Astros21</title>', false)
            ->assertSee('Conheça os atendentes de tarô do Astros21', false)
            ->assertDontSee('Arcana Tarot');

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('<title>Contato — Astros21</title>', false);
    }

    public function test_bulk_email_default_template_uses_the_cms_site_name(): void
    {
        $this->renameSite();

        $template = new BulkEmailTemplate;

        $this->assertSame('Novidades do Astros21', $template->subject());
        $this->assertStringContainsString('Equipe Astros21', $template->body());
    }
}
