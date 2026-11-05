<?php

namespace Tests\Feature;

use App\Enums\PrivacyIncidentStatus;
use App\Enums\SensitiveDataCategory;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\ConsultationPrivacyIncident;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O bloqueio de dado pessoal precisa valer para o formulário HTML, para o chat
 * em tempo real e para qualquer requisição direta ao endpoint de mensagens.
 */
class ChatPrivacyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
    }

    /**
     * @return array{0: User, 1: User, 2: Consultation}
     */
    private function conversation(): array
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();

        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        return [$client, $attendant, $consultation];
    }

    public function test_sensitive_text_is_rejected_and_the_attempt_is_recorded(): void
    {
        [$client, , $consultation] = $this->conversation();
        $body = 'Meu whats é (11) 98765-4321, me chama por fora';

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), ['body' => $body])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ConsultationMessage::query()->count());

        $incident = ConsultationPrivacyIncident::query()->sole();

        $this->assertSame($consultation->id, $incident->consultation_id);
        $this->assertSame($client->id, $incident->user_id);
        $this->assertSame(SensitiveDataCategory::WhatsApp, $incident->category);
        $this->assertSame(PrivacyIncidentStatus::Blocked, $incident->status);
        $this->assertStringContainsString('*****-4321', $incident->masked_excerpt);
        $this->assertStringNotContainsString('98765-4321', $incident->masked_excerpt);

        // O texto completo só existe cifrado no banco.
        $raw = (string) DB::table('consultation_privacy_incidents')->value('body_original');

        $this->assertNotSame($body, $raw);
        $this->assertSame($body, $incident->body_original);
    }

    public function test_realtime_requests_receive_a_validation_error_for_the_body_field(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->postJson(route('consultations.messages.store', $consultation), [
                'body' => 'meu email é maria [at] gmail [dot] com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, ConsultationMessage::query()->count());
        $this->assertSame(1, ConsultationPrivacyIncident::query()->count());
    }

    public function test_direct_requests_cannot_bypass_the_block_by_sending_server_fields(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $this->actingAs($client)
            ->postJson(route('consultations.messages.store', $consultation), [
                'body' => 'cnpj 11.222.333/0001-81',
                'sender_id' => $attendant->id,
                'type' => 'card',
                'created_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, ConsultationMessage::query()->count());

        $incident = ConsultationPrivacyIncident::query()->sole();

        $this->assertSame(SensitiveDataCategory::Cnpj, $incident->category);
        $this->assertSame($client->id, $incident->user_id);
    }

    public function test_clean_text_is_persisted_without_creating_an_incident(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'A consulta custa R$ 50,00 e dura 30 minutos, pode ser às 14:30?',
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');

        $this->assertSame(1, ConsultationMessage::query()->count());
        $this->assertSame(0, ConsultationPrivacyIncident::query()->count());
    }

    public function test_tarot_card_messages_are_not_affected_by_the_privacy_guard(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $deck = TarotDeck::factory()->create(['name' => 'Baralho Lunar']);
        $path = 'cards/'.Str::random(32).'.png';
        Storage::disk('tarot_private')->put($path, 'conteudo');

        $card = TarotCard::factory()->create([
            'deck_id' => $deck->id,
            'name' => 'A Lua',
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_size' => 8,
            'is_active' => true,
        ]);

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');

        $this->assertSame(1, ConsultationMessage::query()->count());
        $this->assertSame(0, ConsultationPrivacyIncident::query()->count());

        // O cliente não envia cartas, e a rota não aceita texto livre: não há
        // caminho alternativo para gravar dado pessoal.
        $this->actingAs($client)
            ->postJson(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
                'body' => 'meu zap é 11 98765-4321',
            ])
            ->assertForbidden();

        $this->assertSame(1, ConsultationMessage::query()->count());
        $this->assertSame(0, ConsultationPrivacyIncident::query()->count());
    }

    public function test_outsiders_cannot_create_incidents_in_a_conversation(): void
    {
        [, , $consultation] = $this->conversation();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->postJson(route('consultations.messages.store', $consultation), [
                'body' => 'meu whats é 11 98765-4321',
            ])
            ->assertForbidden();

        $this->assertSame(0, ConsultationPrivacyIncident::query()->count());
    }

    public function test_the_conversation_keeps_working_after_a_blocked_attempt(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'segue meu contato: maria.silva@gmail.com',
            ])
            ->assertSessionHasErrors('body');

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Vamos seguir por aqui.'])
            ->assertSessionHas('success');

        $this->assertSame(1, ConsultationMessage::query()->count());
        $this->assertSame(1, ConsultationPrivacyIncident::query()->count());
    }
}
