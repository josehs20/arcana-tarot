<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $user
     */
    private function attendant(array $profile = [], array $user = []): AttendantProfile
    {
        $userModel = User::factory()->attendant()->create($user);

        return AttendantProfile::factory()
            ->for($userModel)
            ->create($profile);
    }

    public function test_home_lists_only_online_published_attendants(): void
    {
        $this->attendant([
            'display_name' => 'Ana Online',
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->attendant([
            'display_name' => 'Beto Offline',
            'availability' => AttendantAvailability::Offline->value,
        ]);

        $this->attendant([
            'display_name' => 'Carla Oculta',
            'is_published' => false,
            'availability' => AttendantAvailability::Online->value,
        ]);

        $inactiveUser = User::factory()->attendant()->create(['is_active' => false]);

        AttendantProfile::factory()->for($inactiveUser)->online()->create([
            'display_name' => 'Davi Inativo',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Ana Online')
            ->assertSee('R$ 2,00')
            ->assertDontSee('Beto Offline')
            ->assertDontSee('Carla Oculta')
            ->assertDontSee('Davi Inativo');
    }

    public function test_home_shows_an_empty_state_when_no_attendant_is_online(): void
    {
        $this->attendant([
            'display_name' => 'Beto Offline',
            'availability' => AttendantAvailability::Offline->value,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Nenhum atendente online neste momento')
            ->assertSee('Ver todos os atendentes')
            ->assertDontSee('Beto Offline');
    }

    public function test_directory_lists_published_attendants_with_status_and_server_price(): void
    {
        $this->attendant([
            'display_name' => 'Ana Online',
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->attendant([
            'display_name' => 'Beto Offline',
            'availability' => AttendantAvailability::Offline->value,
        ]);

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('Ana Online')
            ->assertSee('Beto Offline')
            ->assertSee('Online')
            ->assertSee('Offline')
            ->assertSee('R$ 2,00')
            ->assertSee('/min');
    }

    public function test_directory_and_profile_do_not_expose_private_data(): void
    {
        $attendant = $this->attendant([
            'display_name' => 'Perfil Reservado',
            'commission_rate_bps' => 4321,
            'bio' => 'Uma apresentação pública curta.',
        ], [
            'email' => 'sigiloso@example.com',
        ]);

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertDontSee('sigiloso@example.com')
            ->assertDontSee('4321')
            ->assertDontSee('commission');

        $this->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Perfil Reservado')
            ->assertSee('Uma apresentação pública curta.')
            ->assertDontSee('sigiloso@example.com')
            ->assertDontSee('4321')
            ->assertDontSee('commission');
    }

    public function test_public_profile_shows_approved_data_and_guest_authentication_call_to_action(): void
    {
        $attendant = $this->attendant([
            'display_name' => 'Luna Prata',
            'bio' => 'Leituras de amor e carreira.',
            'specialties' => ['Amor', 'Carreira'],
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Luna Prata')
            ->assertSee('Leituras de amor e carreira.')
            ->assertSee('Amor')
            ->assertSee('Carreira')
            ->assertSee('Online')
            ->assertSee('R$ 2,00')
            ->assertSee('Entrar para solicitar')
            ->assertSee(route('login'), false)
            ->assertSee('Você precisa estar autenticado para solicitar uma consulta.');
    }

    public function test_authenticated_client_receives_the_request_form_instead_of_login(): void
    {
        $attendant = $this->attendant([
            'availability' => AttendantAvailability::Online->value,
        ]);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Confirmar e solicitar')
            ->assertSee(route('client.consultations.store'), false)
            ->assertDontSee('Ir para o meu painel')
            ->assertDontSee('Entrar para solicitar');
    }

    public function test_public_profile_and_directory_omit_unpublished_or_inactive_profiles(): void
    {
        $unpublished = $this->attendant(['is_published' => false]);

        $inactiveUser = User::factory()->attendant()->create(['is_active' => false]);
        $inactive = AttendantProfile::factory()->for($inactiveUser)->create();

        $this->get(route('attendants.show', $unpublished))->assertNotFound();
        $this->get(route('attendants.show', $inactive))->assertNotFound();
        $this->get('/atendentes/999999')->assertNotFound();
        $this->get('/atendentes/perfil-invalido')->assertNotFound();
    }

    public function test_busy_status_is_derived_from_an_active_consultation(): void
    {
        $attendant = $this->attendant([
            'display_name' => 'Atendente Ocupada',
            'availability' => AttendantAvailability::Online->value,
        ]);

        Consultation::factory()->active()->create([
            'attendant_id' => $attendant->user_id,
            'client_id' => User::factory()->create()->id,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Atendente Ocupada')
            ->assertSee('Em consulta');

        $this->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Em consulta neste momento.')
            ->assertDontSee('Entrar para solicitar');
    }

    public function test_price_is_unknown_when_attendant_has_no_modality_price(): void
    {
        $user = User::factory()->attendant()->create();

        $attendant = AttendantProfile::factory()
            ->online()
            ->for($user)
            ->withoutModalityPrices()
            ->create();

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('Preço não configurado')
            ->assertDontSee('R$');

        $this->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Não configurado');
    }

    public function test_optional_photo_is_used_when_available_and_falls_back_to_initials(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attendants/luna.jpg', 'conteudo-fake');

        $withPhoto = $this->attendant([
            'display_name' => 'Luna Foto',
            'photo_path' => 'attendants/luna.jpg',
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->get(route('attendants.show', $withPhoto))
            ->assertOk()
            ->assertSee('storage/attendants/luna.jpg', false)
            ->assertSee('alt="Foto de Luna Foto"', false);

        $withoutPhoto = $this->attendant([
            'display_name' => 'Maya Sem Foto',
            'photo_path' => null,
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->get(route('attendants.show', $withoutPhoto))
            ->assertOk()
            ->assertSee('MS')
            ->assertDontSee('storage/attendants/', false);
    }

    public function test_price_is_formatted_from_integer_cents(): void
    {
        $user = User::factory()->attendant()->create();

        AttendantProfile::factory()
            ->online()
            ->for($user)
            ->withModalityPrices(['text' => 199])
            ->create();

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('R$ 1,99');
    }

    public function test_home_lists_active_tarot_decks_with_their_card_counts(): void
    {
        $deck = TarotDeck::factory()->create([
            'name' => 'Tarô de Marselha',
            'description' => 'Baralho tradicional usado nas leituras de caminho.',
            'is_active' => true,
        ]);

        TarotCard::factory()->count(3)->create(['deck_id' => $deck->id]);

        TarotDeck::factory()->create([
            'name' => 'Baralho Oculto',
            'is_active' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Tradições que abrem caminhos diferentes')
            ->assertSee('Tarô de Marselha')
            ->assertSee('3 cartas')
            ->assertDontSee('Baralho Oculto');
    }

    public function test_home_shows_an_empty_state_when_no_deck_is_active(): void
    {
        TarotDeck::factory()->create([
            'name' => 'Baralho Oculto',
            'is_active' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Baralhos em preparação')
            ->assertDontSee('Baralho Oculto');
    }

    public function test_navigation_links_point_to_the_dedicated_pages(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('pages.how-it-works').'"', false)
            ->assertSee('href="'.route('pages.faq').'"', false)
            ->assertDontSee('href="'.route('home').'#como-funciona"', false)
            ->assertDontSee('href="'.route('home').'#faq"', false);
    }

    public function test_how_it_works_page_renders_the_steps_and_the_first_faqs(): void
    {
        $this->get(route('pages.how-it-works'))
            ->assertOk()
            ->assertSee('breadcrumb-arcana', false)
            ->assertSee('Três passos, uma leitura')
            ->assertSee('Passo 01')
            ->assertSee('Escolha o atendente')
            ->assertSee('Preciso saber de tarô?')
            ->assertDontSee('Minhas mensagens ficam privadas?')
            ->assertSee('aria-current="page"', false);
    }

    public function test_faq_page_lists_every_published_question(): void
    {
        $this->get(route('pages.faq'))
            ->assertOk()
            ->assertSee('breadcrumb-arcana', false)
            ->assertSee('Antes de perguntar')
            ->assertSee('Preciso saber de tarô?')
            ->assertSee('Como funciona a cobrança?')
            ->assertSee('Posso cancelar a consulta?')
            ->assertSee('Minhas mensagens ficam privadas?')
            ->assertSee('Falar com a equipe');
    }

    public function test_work_with_us_call_to_action_and_institutional_pages_render(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('work-with-us.index'), false)
            ->assertSee('Trabalhe conosco')
            ->assertSee('não garante aprovação automática');

        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertSee('Formulário de candidatura')
            ->assertSee('name="consent"', false)
            ->assertSee('não garante aprovação')
            ->assertDontSee('Página em preparação');

        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('Termos de uso')
            ->assertSee('Documento provisório')
            ->assertSee('revisão jurídica');

        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('Política de privacidade')
            ->assertSee('Documento provisório')
            ->assertSee('em revisão');

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('Fale com a equipe')
            ->assertSee('Página em preparação');
    }
}
