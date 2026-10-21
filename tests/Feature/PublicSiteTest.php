<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create([
            'key' => 'rate_credits_per_minute',
            'value' => '2',
            'type' => 'integer',
        ]);

        Setting::query()->create([
            'key' => 'credit_unit_price_cents',
            'value' => '150',
            'type' => 'integer',
        ]);
    }

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
            ->assertSee('R$ 3,00')
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
            ->assertSee('R$ 3,00')
            ->assertSee('/ minuto');
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
            ->assertSee('R$ 3,00')
            ->assertSee('Entrar para solicitar')
            ->assertSee(route('login'), false)
            ->assertSee('Você precisa estar autenticado para solicitar uma consulta.');
    }

    public function test_authenticated_client_is_sent_to_the_panel_instead_of_login(): void
    {
        $attendant = $this->attendant([
            'availability' => AttendantAvailability::Online->value,
        ]);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Ir para o meu painel')
            ->assertSee(route('client.dashboard'), false)
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

    public function test_price_falls_back_to_consult_when_settings_are_missing(): void
    {
        Setting::query()->delete();

        $attendant = $this->attendant([
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('Valor sob consulta')
            ->assertDontSee('R$');

        $this->get(route('attendants.show', $attendant))
            ->assertOk()
            ->assertSee('Valor sob consulta');
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
        Setting::query()->where('key', 'rate_credits_per_minute')->update(['value' => '1']);
        Setting::query()->where('key', 'credit_unit_price_cents')->update(['value' => '199']);

        $this->attendant([
            'availability' => AttendantAvailability::Online->value,
        ]);

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('R$ 1,99');
    }

    public function test_work_with_us_call_to_action_and_placeholders_render(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('work-with-us.index'), false)
            ->assertSee('Trabalhe conosco')
            ->assertSee('não garante aprovação automática');

        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertSee('Página em preparação')
            ->assertSee('não garante aprovação automática')
            ->assertSee('não garante aprovação')
            ->assertDontSee('<form', false);

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
