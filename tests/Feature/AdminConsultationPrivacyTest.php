<?php

namespace Tests\Feature;

use App\Enums\MessageType;
use App\Enums\PrivacyIncidentStatus;
use App\Enums\SensitiveDataCategory;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\ConsultationPrivacyIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Painel administrativo de consultas: filtros por ocorrência, histórico
 * mascarado, auditoria de cada acesso e revisão das tentativas bloqueadas.
 */
class AdminConsultationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function consultation(): Consultation
    {
        return Consultation::factory()->active()->create();
    }

    private function incident(Consultation $consultation, SensitiveDataCategory $category = SensitiveDataCategory::Phone): ConsultationPrivacyIncident
    {
        return ConsultationPrivacyIncident::factory()
            ->category($category)
            ->create([
                'consultation_id' => $consultation->getKey(),
                'user_id' => $consultation->client_id,
            ]);
    }

    public function test_index_filters_consultations_by_incident_and_category(): void
    {
        $admin = $this->admin();

        $flagged = $this->consultation();
        $this->incident($flagged, SensitiveDataCategory::WhatsApp);

        $clean = $this->consultation();

        $this->actingAs($admin)
            ->get(route('admin.consultations.index', ['incidents' => 'with']))
            ->assertOk()
            ->assertSee($flagged->client->name)
            ->assertDontSee($clean->client->name)
            ->assertSee('Com ocorrência');

        $this->actingAs($admin)
            ->get(route('admin.consultations.index', ['incidents' => 'without']))
            ->assertOk()
            ->assertSee($clean->client->name)
            ->assertDontSee($flagged->client->name);

        $this->actingAs($admin)
            ->get(route('admin.consultations.index', ['incident_type' => SensitiveDataCategory::WhatsApp->value]))
            ->assertOk()
            ->assertSee($flagged->client->name);

        $this->actingAs($admin)
            ->get(route('admin.consultations.index', ['incident_type' => SensitiveDataCategory::Cpf->value]))
            ->assertOk()
            ->assertDontSee($flagged->client->name);
    }

    public function test_index_rejects_unknown_incident_filters(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.consultations.index', ['incidents' => 'talvez', 'incident_type' => 'senha']))
            ->assertSessionHasErrors(['incidents', 'incident_type']);
    }

    public function test_show_renders_the_masked_incident_without_the_original_text(): void
    {
        $admin = $this->admin();
        $consultation = $this->consultation();
        $incident = $this->incident($consultation);

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('id="ocorrencias"', false)
            ->assertSee($incident->masked_excerpt)
            ->assertSee('Tentativa bloqueada')
            ->assertDontSee('Meu telefone é (11) 98765-4321');
    }

    public function test_opening_the_history_is_audited(): void
    {
        $admin = $this->admin();
        $consultation = $this->consultation();

        ConsultationMessage::factory()->count(3)->create([
            'consultation_id' => $consultation->getKey(),
            'sender_id' => $consultation->client_id,
        ]);
        $this->incident($consultation);

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk();

        $log = AuditLog::query()->where('action', 'consultation_history.viewed')->sole();

        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame($consultation->getKey(), $log->subject_id);
        $this->assertSame(3, $log->metadata['messages']);
        $this->assertSame(1, $log->metadata['privacy_incidents']);
        $this->assertTrue($log->metadata['is_live']);
    }

    public function test_entries_endpoint_returns_only_items_after_the_cursors(): void
    {
        $admin = $this->admin();
        $consultation = $this->consultation();

        $message = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->getKey(),
            'sender_id' => $consultation->client_id,
            'body' => 'Mensagem nova no histórico',
        ]);
        $incident = $this->incident($consultation);

        $response = $this->actingAs($admin)->getJson(route('admin.consultations.entries', [
            'consultation' => $consultation,
            'after_message_id' => 0,
            'after_incident_id' => 0,
        ]));

        $response->assertOk()
            ->assertJsonPath('last_message_id', $message->getKey())
            ->assertJsonPath('last_incident_id', $incident->getKey())
            ->assertJsonPath('has_more', false);

        $html = (string) $response->json('html');

        $this->assertStringContainsString('Mensagem nova no histórico', $html);
        $this->assertStringContainsString($incident->masked_excerpt, $html);
        $this->assertStringNotContainsString('98765-4321,', $html);

        // Com os cursores no fim, nada é reenviado e os cursores não voltam.
        $this->actingAs($admin)
            ->getJson(route('admin.consultations.entries', [
                'consultation' => $consultation,
                'after_message_id' => $message->getKey(),
                'after_incident_id' => $incident->getKey(),
            ]))
            ->assertOk()
            ->assertJsonPath('last_message_id', $message->getKey())
            ->assertJsonPath('last_incident_id', $incident->getKey())
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('html', '');
    }

    public function test_entries_endpoint_validates_the_cursors(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('admin.consultations.entries', [
                'consultation' => $this->consultation(),
                'after_message_id' => 'abc',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('after_message_id');
    }

    public function test_incident_review_is_recorded_and_audited(): void
    {
        $admin = $this->admin();
        $consultation = $this->consultation();
        $incident = $this->incident($consultation);

        $this->actingAs($admin)
            ->patch(route('admin.consultations.incidents.review', [
                'consultation' => $consultation,
                'incident' => $incident,
            ]), ['review_notes' => 'Contato confirmado como tentativa de negociação externa.'])
            ->assertRedirect(route('admin.consultations.show', $consultation).'#ocorrencias');

        $incident->refresh();

        $this->assertSame(PrivacyIncidentStatus::Reviewed, $incident->status);
        $this->assertSame($admin->getKey(), $incident->reviewed_by);
        $this->assertNotNull($incident->reviewed_at);
        $this->assertSame('Contato confirmado como tentativa de negociação externa.', $incident->review_notes);

        $log = AuditLog::query()->where('action', 'consultation_privacy_incident.reviewed')->sole();

        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame($incident->getKey(), $log->metadata['incident_id']);
        $this->assertSame(SensitiveDataCategory::Phone->value, $log->metadata['category']);
    }

    public function test_an_incident_cannot_be_reviewed_through_another_consultation(): void
    {
        $admin = $this->admin();
        $consultation = $this->consultation();
        $other = $this->consultation();
        $otherIncident = $this->incident($other);

        $this->actingAs($admin)
            ->patch(route('admin.consultations.incidents.review', [
                'consultation' => $consultation,
                'incident' => $otherIncident,
            ]))
            ->assertNotFound();

        $this->assertTrue($otherIncident->refresh()->isOpen());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_only_active_admins_can_read_the_admin_history(): void
    {
        $consultation = $this->consultation();
        $incident = $this->incident($consultation);

        $client = User::factory()->create();
        $attendant = $consultation->attendant;
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);

        $this->actingAs($client)
            ->get(route('admin.consultations.show', $consultation))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->getJson(route('admin.consultations.entries', ['consultation' => $consultation]))
            ->assertForbidden();

        $this->actingAs($client)
            ->patch(route('admin.consultations.incidents.review', [
                'consultation' => $consultation,
                'incident' => $incident,
            ]))
            ->assertForbidden();

        $this->actingAs($inactiveAdmin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertForbidden();

        $this->assertTrue($incident->refresh()->isOpen());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_the_admin_card_image_route_is_audited_and_limited_to_card_messages(): void
    {
        Storage::fake('tarot_private');

        $admin = $this->admin();
        $consultation = $this->consultation();

        $path = 'consultation-cards/'.$consultation->getKey().'/carta.png';
        Storage::disk('tarot_private')->put(
            $path,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='),
        );

        $cardMessage = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->getKey(),
            'sender_id' => $consultation->attendant_id,
            'type' => MessageType::TarotCard->value,
            'body' => null,
            'card_name' => 'A Lua',
            'deck_name' => 'Baralho Lunar',
            'image_path' => $path,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.consultations.messages.image', [
                'consultation' => $consultation,
                'message' => $cardMessage,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $log = AuditLog::query()->where('action', 'consultation_history.card_image_viewed')->sole();

        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame($cardMessage->getKey(), $log->metadata['message_id']);

        // Mensagem de texto não tem imagem: 404 sem registrar auditoria.
        $textMessage = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->getKey(),
            'sender_id' => $consultation->client_id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.consultations.messages.image', [
                'consultation' => $consultation,
                'message' => $textMessage,
            ]))
            ->assertNotFound();

        $this->assertSame(1, AuditLog::query()->where('action', 'consultation_history.card_image_viewed')->count());
    }
}
