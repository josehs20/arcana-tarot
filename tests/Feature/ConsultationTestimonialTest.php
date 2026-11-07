<?php

namespace Tests\Feature;

use App\Enums\ConsultationStatus;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\ConsultationTestimonial;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Depoimentos pós-consulta.
 *
 * Cobre o envio pelo cliente (somente em consultas `ended`, uma vez por
 * atendimento, sem contato externo no texto), a moderação administrativa
 * (ocultar/republicar com auditoria) e a exibição pública no perfil do
 * atendente, sempre com o nome reduzido do cliente.
 */
class ConsultationTestimonialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '150', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    /**
     * Cliente, atendente dono do perfil e consulta encerrada pronta para avaliar.
     *
     * @return array{0: User, 1: User, 2: AttendantProfile, 3: Consultation}
     */
    private function endedConsultation(): array
    {
        $client = User::factory()->create(['name' => 'Maria Silva']);
        $attendant = User::factory()->attendant()->create(['name' => 'Joana Prado']);
        $profile = AttendantProfile::factory()->for($attendant)->create(['display_name' => 'Mãe Joana']);

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(2),
        ]);

        return [$client, $attendant, $profile, $consultation];
    }

    public function test_client_can_submit_a_testimonial_for_an_ended_consultation(): void
    {
        [$client, , , $consultation] = $this->endedConsultation();

        $this->actingAs($client)
            ->post(route('client.testimonials.store', $consultation), [
                'rating' => 5,
                'message' => 'Atendimento acolhedor e muito claro nas respostas.',
            ])
            ->assertRedirect(route('client.testimonials.index'))
            ->assertSessionHas('success');

        $testimonial = ConsultationTestimonial::query()->firstOrFail();

        $this->assertSame($consultation->id, $testimonial->consultation_id);
        $this->assertSame($client->id, $testimonial->client_id);
        $this->assertSame(5, $testimonial->rating);
        $this->assertTrue($testimonial->isVisible());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'consultation_testimonial.created',
            'actor_id' => $client->id,
            'subject_id' => $testimonial->id,
        ]);
    }

    public function test_only_ended_consultations_accept_a_testimonial(): void
    {
        [$client, , , $ended] = $this->endedConsultation();

        $active = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $ended->attendant_id,
        ]);

        $this->actingAs($client)
            ->post(route('client.testimonials.store', $active), [
                'rating' => 4,
                'message' => 'Consulta ainda em andamento não deveria ser avaliada.',
            ])
            ->assertForbidden();

        $this->assertSame(0, ConsultationTestimonial::query()->count());
    }

    public function test_a_consultation_can_be_rated_only_once(): void
    {
        [$client, , , $consultation] = $this->endedConsultation();

        ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $consultation->attendant_id,
            'rating' => 3,
        ]);

        $this->actingAs($client)
            ->post(route('client.testimonials.store', $consultation), [
                'rating' => 5,
                'message' => 'Tentando avaliar a mesma consulta duas vezes.',
            ])
            ->assertForbidden();

        $this->assertSame(1, ConsultationTestimonial::query()->count());
    }

    public function test_other_clients_cannot_rate_someone_elses_consultation(): void
    {
        [, , , $consultation] = $this->endedConsultation();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('client.testimonials.store', $consultation), [
                'rating' => 1,
                'message' => 'Depoimento de quem não participou da consulta.',
            ])
            ->assertForbidden();

        $this->assertSame(0, ConsultationTestimonial::query()->count());
    }

    public function test_testimonial_with_contact_information_is_rejected(): void
    {
        [$client, , , $consultation] = $this->endedConsultation();

        $this->actingAs($client)
            ->post(route('client.testimonials.store', $consultation), [
                'rating' => 5,
                'message' => 'Adorei! Me chama no whatsapp 11 98888-7777 para outra consulta.',
            ])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, ConsultationTestimonial::query()->count());
    }

    public function test_rating_and_message_length_are_validated(): void
    {
        [$client, , , $consultation] = $this->endedConsultation();

        $this->actingAs($client)
            ->post(route('client.testimonials.store', $consultation), [
                'rating' => 9,
                'message' => 'curto',
            ])
            ->assertSessionHasErrors(['rating', 'message']);

        $this->assertSame(0, ConsultationTestimonial::query()->count());
    }

    public function test_admin_can_hide_and_republish_a_testimonial(): void
    {
        [$client, $attendant, $profile, $consultation] = $this->endedConsultation();
        $admin = User::factory()->admin()->create();

        $testimonial = ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 5,
            'message' => 'Leitura precisa e cuidadosa do começo ao fim.',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.testimonials.hide', $testimonial))
            ->assertRedirect()
            ->assertSessionHas('success');

        $testimonial->refresh();

        $this->assertTrue($testimonial->is_hidden);
        $this->assertSame($admin->id, $testimonial->hidden_by_id);
        $this->assertNotNull($testimonial->hidden_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'consultation_testimonial.hidden',
            'actor_id' => $admin->id,
            'subject_id' => $testimonial->id,
        ]);

        $this->actingAs($client)
            ->get(route('attendants.show', $profile->id))
            ->assertOk()
            ->assertDontSee('Leitura precisa e cuidadosa do começo ao fim.');

        $this->actingAs($admin)
            ->patch(route('admin.testimonials.publish', $testimonial))
            ->assertRedirect()
            ->assertSessionHas('success');

        $testimonial->refresh();

        $this->assertFalse($testimonial->is_hidden);
        $this->assertNull($testimonial->hidden_by_id);
        $this->assertNull($testimonial->hidden_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'consultation_testimonial.published',
            'actor_id' => $admin->id,
            'subject_id' => $testimonial->id,
        ]);
    }

    public function test_public_profile_shows_only_visible_testimonials_with_short_client_name(): void
    {
        [$client, $attendant, $profile, $consultation] = $this->endedConsultation();

        ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 4,
            'message' => 'Conversa gentil e conselhos que fizeram sentido.',
        ]);

        $hiddenConsultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(50),
            'ended_at' => now()->subMinutes(30),
        ]);

        ConsultationTestimonial::factory()->hidden()->create([
            'consultation_id' => $hiddenConsultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 1,
            'message' => 'Texto removido pela moderação.',
        ]);

        $this->get(route('attendants.show', $profile->id))
            ->assertOk()
            ->assertSee('id="depoimentos"', false)
            ->assertSee('Conversa gentil e conselhos que fizeram sentido.')
            ->assertSee('Maria S.')
            ->assertDontSee('Maria Silva')
            ->assertDontSee('Texto removido pela moderação.')
            ->assertSee('4,0');
    }

    public function test_client_panel_lists_pending_and_submitted_testimonials(): void
    {
        [$client, $attendant, , $consultation] = $this->endedConsultation();

        $pending = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(40),
            'ended_at' => now()->subMinutes(10),
        ]);

        ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 5,
            'message' => 'Depoimento já enviado pelo cliente.',
        ]);

        $this->actingAs($client)
            ->get(route('client.testimonials.index'))
            ->assertOk()
            ->assertSee('Aguardando seu depoimento')
            ->assertSee('Depoimentar')
            ->assertSee('testimonial-modal-'.$pending->id, false)
            ->assertSee('Depoimento já enviado pelo cliente.')
            ->assertSee('Publicado');
    }

    public function test_consultation_page_prompts_the_client_to_rate_after_ending(): void
    {
        [$client, , , $consultation] = $this->endedConsultation();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-testimonial-prompt', false)
            ->assertSee('Como foi sua consulta?')
            ->assertSee('Enviar depoimento');
    }

    public function test_testimonial_prompt_collects_the_rating_through_five_star_radios(): void
    {
        [$client, , , $consultation] = $this->endedConsultation();

        $response = $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-star-rating', false)
            ->assertSee('Como foi sua consulta?');

        preg_match_all('/name="rating"\s+value="(\d)"/', $response->getContent(), $matches);

        $this->assertSame(['1', '2', '3', '4', '5'], $matches[1]);
    }

    public function test_consultation_page_hides_the_prompt_once_the_testimonial_exists(): void
    {
        [$client, $attendant, , $consultation] = $this->endedConsultation();

        ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 5,
            'message' => 'Depoimento enviado antes de abrir a tela da consulta.',
        ]);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('data-testimonial-prompt', false)
            ->assertDontSee('Como foi sua consulta?');
    }

    public function test_consultation_page_keeps_a_persistent_invitation_until_the_testimonial_is_written(): void
    {
        [$client, $attendant, , $consultation] = $this->endedConsultation();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-testimonial-invite', false)
            ->assertSee('Como foi a consulta?')
            ->assertSee('Avaliar atendimento')
            ->assertSee('data-bs-target="#testimonial-modal-'.$consultation->getKey().'"', false);

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('data-testimonial-invite', false);

        ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 4,
            'message' => 'Depoimento escrito antes de reabrir a tela da consulta.',
        ]);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('data-testimonial-invite', false)
            ->assertDontSee('Como foi a consulta?')
            ->assertDontSee('data-testimonial-prompt', false);
    }

    public function test_admin_moderation_page_lists_testimonials_and_blocks_non_admins(): void
    {
        [$client, $attendant, , $consultation] = $this->endedConsultation();
        $admin = User::factory()->admin()->create();

        $testimonial = ConsultationTestimonial::factory()->create([
            'consultation_id' => $consultation->id,
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rating' => 2,
            'message' => 'Depoimento em análise pela moderação.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.testimonials.index'))
            ->assertOk()
            ->assertSee('Depoimento em análise pela moderação.')
            ->assertSee('Maria Silva')
            ->assertSee('Maria S.')
            ->assertSee('Ocultar')
            ->assertSee(route('admin.testimonials.hide', $testimonial), false);

        $this->actingAs($client)
            ->get(route('admin.testimonials.index'))
            ->assertForbidden();
    }

    public function test_empty_states_render_for_client_panel_and_admin_moderation(): void
    {
        $client = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($client)
            ->get(route('client.testimonials.index'))
            ->assertOk()
            ->assertSee('Nenhuma consulta pendente de avaliação')
            ->assertSee('Você ainda não avaliou nenhum atendimento');

        $this->actingAs($admin)
            ->get(route('admin.testimonials.index'))
            ->assertOk()
            ->assertSee('Nenhum depoimento encontrado');
    }
}
