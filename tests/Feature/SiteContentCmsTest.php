<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Models\SiteNavigationLink;
use App\Models\SiteSection;
use App\Models\User;
use App\Support\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteContentCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_the_site_identity_and_the_home_reflects_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.identity.update'), [
                'site_name' => 'Arcana CMS',
                'site_description' => 'Descrição editada pelo painel administrativo.',
            ])
            ->assertRedirect(route('admin.site.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', [
            'key' => 'site_name',
            'value' => 'Arcana CMS',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Arcana CMS')
            ->assertSee('Descrição editada pelo painel administrativo.');

        $this->assertSame(1, AuditLog::query()->where('action', 'site.identity_updated')->count());
    }

    public function test_admin_can_publish_and_unpublish_a_home_section(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'hero']), [
                'title' => 'Título exclusivo do CMS',
                'is_published' => '1',
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'hero']));

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Título exclusivo do CMS');

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'hero']), [
                'title' => 'Título exclusivo do CMS',
                'is_published' => '0',
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'hero']));

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Título exclusivo do CMS');

        $section = SiteSection::query()->where('key', 'hero')->firstOrFail();
        $this->assertFalse($section->is_published);
        $this->assertSame('Título exclusivo do CMS', $section->title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.section_updated']);
    }

    public function test_home_follows_the_section_order_defined_in_the_cms(): void
    {
        SiteSection::query()->create([
            'key' => 'hero',
            'title' => 'Hero definido para depois',
            'is_published' => true,
            'sort_order' => 20,
        ]);

        SiteSection::query()->create([
            'key' => 'how_it_works',
            'title' => 'Como funciona definido para antes',
            'is_published' => true,
            'sort_order' => 10,
        ]);

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder([
                'Como funciona definido para antes',
                'Hero definido para depois',
            ]);
    }

    public function test_admin_can_manage_faq_items_and_the_home_only_lists_active_ones(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.site.faqs.store'), [
                'question' => 'Pergunta criada no CMS?',
                'answer' => 'Resposta criada no CMS.',
                'is_active' => '1',
                'sort_order' => 5,
            ])
            ->assertRedirect(route('admin.site.faqs.index'));

        $this->actingAs($admin)
            ->post(route('admin.site.faqs.store'), [
                'question' => 'Pergunta desativada?',
                'answer' => 'Não deve aparecer na home.',
                'is_active' => '0',
            ])
            ->assertRedirect(route('admin.site.faqs.index'));

        $this->assertSame(2, FaqItem::query()->count());

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Pergunta criada no CMS?')
            ->assertSee('Resposta criada no CMS.')
            ->assertDontSee('Pergunta desativada?');

        $this->assertSame(2, AuditLog::query()->where('action', 'site.faq_created')->count());
    }

    public function test_navigation_links_reject_targets_outside_the_allowlist(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.site.navigation.store'), [
                'label' => 'Painel proibido',
                'group' => 'primary',
                'target_type' => 'route',
                'target' => 'admin.dashboard',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('target');

        $this->assertSame(0, SiteNavigationLink::query()->count());

        $this->actingAs($admin)
            ->post(route('admin.site.navigation.store'), [
                'label' => 'Atendentes CMS',
                'group' => 'primary',
                'target_type' => 'route',
                'target' => 'attendants.index',
                'is_active' => '1',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.site.navigation.index'));

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Atendentes CMS');

        $this->assertDatabaseHas('site_navigation_links', [
            'label' => 'Atendentes CMS',
            'target_type' => 'route',
            'target' => 'attendants.index',
        ]);
    }

    public function test_cms_images_reject_invalid_files_and_store_valid_ones_publicly(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.identity.update'), [
                'site_name' => 'Arcana CMS',
                'site_description' => 'Descrição do site.',
                'logo' => UploadedFile::fake()->create('malicioso.php', 4, 'application/x-php'),
            ])
            ->assertSessionHasErrors('logo');

        $this->assertEmpty(Storage::disk('public')->allFiles('site'));

        $this->actingAs($admin)
            ->patch(route('admin.site.identity.update'), [
                'site_name' => 'Arcana CMS',
                'site_description' => 'Descrição do site.',
                'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
            ])
            ->assertRedirect(route('admin.site.index'));

        $path = Setting::query()->where('key', 'site_logo_path')->value('value');

        $this->assertIsString($path);
        $this->assertStringStartsWith('site/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_home_renders_the_body_secondary_cta_and_image_edited_in_a_section(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site/atendentes.png', 'conteudo-fake');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'attendants']), [
                'title' => 'Atendentes atualizados',
                'body' => "Primeira linha do texto.\nSegunda linha do texto.",
                'secondary_cta_label' => 'Ver dúvidas',
                'secondary_cta_target' => 'pages.faq',
                'is_published' => '1',
                'sort_order' => 20,
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'attendants']))
            ->assertSessionHasNoErrors();

        SiteSection::query()
            ->where('key', 'attendants')
            ->update([
                'image_path' => 'site/atendentes.png',
                'image_alt' => 'Equipe do Arcana',
            ]);

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Atendentes atualizados')
            ->assertSee('Primeira linha do texto.<br />', false)
            ->assertSee('Segunda linha do texto.')
            ->assertSee('Ver dúvidas')
            ->assertSee('href="'.route('pages.faq').'"', false)
            ->assertSee('alt="Equipe do Arcana"', false)
            ->assertSee(Storage::disk('public')->url('site/atendentes.png'), false);

        // A tela de edição avisa que parte do conteúdo vem de outra área e
        // renderiza normalmente em seções sem aviso configurado.
        $this->get(route('admin.site.sections.edit', ['key' => 'attendants']))
            ->assertOk()
            ->assertSee((string) config('site.section_hints.attendants'));

        $this->get(route('admin.site.sections.edit', ['key' => 'hero']))
            ->assertOk();
    }

    public function test_section_cta_accepts_the_page_path_shown_in_the_browser(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'faq']), [
                'title' => 'Dúvidas frequentes',
                'primary_cta_label' => 'Contato',
                'primary_cta_target' => '/contato',
                'is_published' => '1',
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'faq']))
            ->assertSessionHasNoErrors();

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('contact').'"', false);

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'faq']), [
                'title' => 'Dúvidas frequentes',
                'primary_cta_label' => 'Painel',
                'primary_cta_target' => '/admin',
                'is_published' => '1',
            ])
            ->assertSessionHasErrors('primary_cta_target');
    }

    public function test_only_admins_can_access_cms_routes(): void
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();

        $this->actingAs($client)
            ->get(route('admin.site.index'))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->patch(route('admin.site.sections.update', ['key' => 'hero']), ['title' => 'Tentativa'])
            ->assertForbidden();

        $this->assertSame(0, SiteSection::query()->count());

        auth()->logout();

        $this->get(route('admin.site.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_manage_the_tarot_cards_section_and_link_to_the_new_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'tarot_cards']), [
                'title' => 'Baralhos do Arcana',
                'is_published' => '1',
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'tarot_cards']));

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Baralhos do Arcana');

        $this->actingAs($admin)
            ->post(route('admin.site.navigation.store'), [
                'label' => 'Como funciona CMS',
                'group' => 'primary',
                'target_type' => 'route',
                'target' => 'pages.how-it-works',
                'is_active' => '1',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.site.navigation.index'))
            ->assertSessionHasNoErrors();

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Como funciona CMS')
            ->assertSee('href="'.route('pages.how-it-works').'"', false);
    }

    public function test_pages_use_the_content_defined_in_the_cms(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'how_it_works']), [
                'title' => 'Um novo passo a passo',
                'subtitle' => 'Subtítulo vindo do painel.',
                'is_published' => '1',
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'how_it_works']));

        $this->actingAs($admin)
            ->patch(route('admin.site.sections.update', ['key' => 'faq']), [
                'title' => 'Perguntas do painel',
                'is_published' => '1',
            ])
            ->assertRedirect(route('admin.site.sections.edit', ['key' => 'faq']));

        $this->app->forgetInstance(SiteContent::class);

        $this->get(route('pages.how-it-works'))
            ->assertOk()
            ->assertSee('Um novo passo a passo')
            ->assertSee('Subtítulo vindo do painel.');

        $this->get(route('pages.faq'))
            ->assertOk()
            ->assertSee('Perguntas do painel');
    }
}
