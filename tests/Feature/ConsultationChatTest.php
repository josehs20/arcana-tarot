<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Enums\MessageType;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Support\ConsultationMessageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultationChatTest extends TestCase
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

    /**
     * @return array{0: TarotCard, 1: TarotDeck}
     */
    private function card(bool $cardActive = true, bool $deckActive = true): array
    {
        $deck = TarotDeck::factory()->create([
            'name' => 'Baralho Solar',
            'is_active' => $deckActive,
        ]);

        $image = $this->imageBytes();
        $path = 'cards/'.Str::random(32).'.png';
        Storage::disk('tarot_private')->put($path, $image);

        $card = TarotCard::factory()->create([
            'deck_id' => $deck->id,
            'name' => 'A Estrela',
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_size' => strlen($image),
            'is_active' => $cardActive,
        ]);

        return [$card, $deck];
    }

    private function imageBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }

    /**
     * Segunda carta do mesmo baralho, para os testes de envio em lote.
     */
    private function cardInDeck(TarotDeck $deck, string $name): TarotCard
    {
        $image = $this->imageBytes();
        $path = 'cards/'.Str::random(32).'.png';
        Storage::disk('tarot_private')->put($path, $image);

        return TarotCard::factory()->create([
            'deck_id' => $deck->id,
            'name' => $name,
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_size' => strlen($image),
            'is_active' => true,
        ]);
    }

    public function test_participants_can_send_and_read_text_messages(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => '  Olá, atendente!  ',
                'sender_id' => $attendant->id,
                'type' => MessageType::TarotCard->value,
                'created_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat')
            ->assertSessionHas('success');

        $message = ConsultationMessage::query()->sole();

        $this->assertSame($consultation->id, $message->consultation_id);
        $this->assertSame($client->id, $message->sender_id);
        $this->assertSame(MessageType::Text, $message->type);
        $this->assertSame('Olá, atendente!', $message->body);
        $this->assertNotNull($message->created_at);

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Olá, atendente!')
            ->assertSee($client->name)
            ->assertSee('Cliente');
    }

    public function test_non_participants_and_administrators_cannot_read_or_send_messages(): void
    {
        [$client, , $consultation] = $this->conversation();
        $message = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Conteúdo privado da consulta',
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('consultations.show', $consultation))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Invasão'])
            ->assertForbidden();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee($message->body)
            ->assertSee('conversa entre cliente e atendente é privada');

        $this->actingAs($admin)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Invasão'])
            ->assertForbidden();
    }

    public function test_ended_consultations_keep_history_but_reject_new_messages_and_cards(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();
        [$card] = $this->card();

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Mensagem anterior'])
            ->assertRedirect();

        $consultation->update([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Depois do fim'])
            ->assertForbidden();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertForbidden();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Mensagem anterior')
            ->assertSee('Somente leitura');

        $this->assertSame(1, ConsultationMessage::query()->count());
    }

    public function test_text_validation_enforces_required_content_and_maximum_length(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.messages.store', $consultation), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->actingAs($client)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.messages.store', $consultation), [
                'body' => str_repeat('a', (int) config('consultation.message_max_length') + 1),
            ])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('consultation_messages', 0);
    }

    public function test_history_is_paginated_and_presented_in_chronological_order(): void
    {
        config(['consultation.message_page_size' => 10]);

        [$client, , $consultation] = $this->conversation();

        foreach (range(1, 12) as $number) {
            ConsultationMessage::factory()->create([
                'consultation_id' => $consultation->id,
                'sender_id' => $client->id,
                'body' => sprintf('Mensagem %02d', $number),
            ]);
        }

        $firstPage = $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Mensagem 12')
            ->assertDontSee('Mensagem 01');

        $messages = $firstPage->viewData('messages');

        $this->assertSame(12, $messages->total());
        $this->assertCount(10, $messages->items());
        $this->assertSame('Mensagem 03', $messages->first()->body);
        $this->assertSame('Mensagem 12', $messages->last()->body);

        $this->actingAs($client)
            ->get(route('consultations.show', ['consultation' => $consultation, 'page' => 2]))
            ->assertOk()
            ->assertSee('Mensagem 01')
            ->assertSee('Mensagem 02')
            ->assertDontSee('Mensagem 12');
    }

    public function test_attendant_can_send_a_card_with_a_private_historical_snapshot(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();
        [$card, $deck] = $this->card();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
                'sender_id' => $client->id,
                'deck_name' => 'Baralho falso',
                'card_name' => 'Carta falsa',
                'image_path' => '../../etc/passwd',
                'created_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat')
            ->assertSessionHas('success');

        $message = ConsultationMessage::query()->sole();

        $this->assertSame(MessageType::TarotCard, $message->type);
        $this->assertNull($message->body);
        $this->assertSame($attendant->id, $message->sender_id);
        $this->assertSame($card->id, $message->tarot_card_id);
        $this->assertSame($deck->name, $message->deck_name);
        $this->assertSame($card->name, $message->card_name);
        $this->assertStringStartsWith("consultation-cards/{$consultation->id}/", $message->image_path);
        $this->assertNotSame($card->image_path, $message->image_path);
        Storage::disk('tarot_private')->assertExists($message->image_path);
        $this->assertSame(
            Storage::disk('tarot_private')->get($card->image_path),
            Storage::disk('tarot_private')->get($message->image_path),
        );

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Enviar cartas')
            ->assertSee('A Estrela')
            ->assertSee($deck->name);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('A Estrela')
            ->assertSee($deck->name);

        $image = $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $message]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('private', (string) $image->headers->get('Cache-Control'));
    }

    public function test_card_snapshot_survives_catalog_changes_and_ended_consultation(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();
        [$card, $deck] = $this->card();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertRedirect();

        $message = ConsultationMessage::query()->sole();
        $snapshotPath = $message->image_path;
        $snapshotContents = Storage::disk('tarot_private')->get($snapshotPath);

        $replacement = 'cards/'.Str::random(32).'.png';
        Storage::disk('tarot_private')->put($replacement, $this->imageBytes());

        $card->update([
            'name' => 'A Lua',
            'image_path' => $replacement,
            'is_active' => false,
        ]);
        $deck->update(['is_active' => false]);

        $consultation->update([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $message->refresh();

        $this->assertSame($snapshotPath, $message->image_path);
        $this->assertSame('A Estrela', $message->card_name);
        $this->assertSame($deck->name, $message->deck_name);
        $this->assertSame($snapshotContents, Storage::disk('tarot_private')->get($snapshotPath));

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('A Estrela')
            ->assertDontSee('A Lua');

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $message]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_only_the_assigned_attendant_can_send_cards(): void
    {
        [$client, , $consultation] = $this->conversation();
        [$card] = $this->card();

        $this->actingAs($client)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertForbidden();

        $otherAttendant = User::factory()->attendant()->create();

        $this->actingAs($otherAttendant)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertForbidden();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertForbidden();

        $this->assertDatabaseCount('consultation_messages', 0);
    }

    public function test_card_send_rejects_inactive_deck_inactive_card_and_missing_image(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();
        [$card, $deck] = $this->card();

        $card->update(['is_active' => false]);

        $this->actingAs($attendant)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertSessionHasErrors('tarot_card_ids');

        $card->update(['is_active' => true]);
        $deck->update(['is_active' => false]);

        $this->actingAs($attendant)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertSessionHasErrors('tarot_card_ids');

        $deck->update(['is_active' => true]);
        Storage::disk('tarot_private')->delete($card->image_path);

        $this->actingAs($attendant)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertSessionHasErrors('tarot_card_ids');

        $this->assertDatabaseCount('consultation_messages', 0);
    }

    public function test_card_snapshot_image_access_is_limited_to_consultation_participants(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();
        [$card] = $this->card();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), ['tarot_card_id' => $card->id])
            ->assertRedirect();

        $cardMessage = ConsultationMessage::query()->sole();
        $textMessage = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'type' => MessageType::Text->value,
        ]);

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->get(route('consultations.messages.image', [$consultation, $textMessage]))
            ->assertNotFound();

        [, $otherAttendant, $otherConsultation] = $this->conversation();
        $foreignMessage = ConsultationMessage::factory()->create([
            'consultation_id' => $otherConsultation->id,
            'sender_id' => $otherAttendant->id,
            'type' => MessageType::TarotCard->value,
            'body' => null,
            'deck_name' => 'Outro baralho',
            'card_name' => 'Outra carta',
            'image_path' => 'consultation-cards/foreign.png',
        ]);

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $foreignMessage]))
            ->assertNotFound();

        $consultation->update([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertOk();
    }

    public function test_message_text_is_escaped_when_rendered(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => '<b>negrito</b>',
        ]);

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('<b>negrito</b>', false)
            ->assertSee('&lt;b&gt;negrito&lt;/b&gt;', false);
    }

    public function test_unread_indicator_is_cleared_after_opening_the_chat(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => 'Nova orientação para você',
        ]);

        $this->assertSame(1, $consultation->unreadMessageCountFor($client));

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('1 nova(s)');

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('1 mensagem nova foi recebida');

        $this->assertNotNull($consultation->fresh()->client_last_read_at);
        $this->assertSame(0, $consultation->fresh()->unreadMessageCountFor($client));

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('1 nova(s)');
    }

    public function test_text_message_frequency_is_limited_per_participant_and_consultation(): void
    {
        config(['consultation.message_rate_per_minute' => 2]);

        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client);

        $this->post(route('consultations.messages.store', $consultation), ['body' => 'Primeira'])
            ->assertRedirect();

        $this->post(route('consultations.messages.store', $consultation), ['body' => 'Segunda'])
            ->assertRedirect();

        $this->post(route('consultations.messages.store', $consultation), ['body' => 'Terceira'])
            ->assertStatus(429);

        $this->assertSame(2, ConsultationMessage::query()->count());
    }

    public function test_attendant_sees_the_card_picker_with_card_images(): void
    {
        [, $attendant, $consultation] = $this->conversation();
        [$card] = $this->card();

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('chat-card-picker', false)
            ->assertSee('name="tarot_card_ids[]"', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('data-max-cards="'.ConsultationMessageManager::MAX_CARDS_PER_SEND.'"', false)
            ->assertSee('Baralho Solar')
            ->assertSee('A Estrela')
            // A grade usa a miniatura reduzida; o original só é aberto na
            // pré-visualização ampliada (`data-card-image`).
            ->assertSee('src="'.route('tarot.cards.thumb', $card), false)
            ->assertSee('data-card-image="'.route('tarot.cards.image', $card), false)
            // O seletor antigo (lista simples) não existe mais.
            ->assertDontSee('<select name="tarot_card_id"', false);
    }

    public function test_card_picker_is_not_rendered_for_the_client(): void
    {
        [$client, , $consultation] = $this->conversation();
        $this->card();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('chat-card-picker', false)
            ->assertDontSee('name="tarot_card_id"', false)
            // Visão do cliente: sem o cartão de participantes e com o nome do
            // atendente já no subtítulo da página.
            ->assertDontSee('Participantes')
            ->assertSee('Conversa com '.$consultation->attendant->name);
    }

    /**
     * Várias cartas em um único envio: a rota aceita a lista, grava tudo na
     * mesma transação e devolve as mensagens criadas para o chat inserir sem
     * recarregar a página.
     */
    public function test_attendant_can_send_several_cards_in_a_single_request(): void
    {
        [, $attendant, $consultation] = $this->conversation();
        [$card, $deck] = $this->card();
        $second = $this->cardInDeck($deck, 'O Sol');
        $third = $this->cardInDeck($deck, 'A Lua');

        $response = $this->actingAs($attendant)
            ->postJson(route('consultations.cards.store', $consultation), [
                'tarot_card_ids' => [$third->id, $card->id, $second->id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('notice', '3 cartas enviadas.')
            ->assertJsonCount(3, 'messages')
            ->assertJsonPath('messages.0.card_name', 'A Lua')
            ->assertJsonPath('messages.1.card_name', 'A Estrela')
            ->assertJsonPath('messages.2.card_name', 'O Sol')
            ->assertJsonPath('messages.0.deck_name', $deck->name)
            ->assertJsonPath('messages.0.type', MessageType::TarotCard->value)
            // Compatibilidade com clientes antigos, que leem `message`.
            ->assertJsonPath('message.card_name', 'A Lua');

        $messages = ConsultationMessage::query()->orderBy('id')->get();

        $this->assertCount(3, $messages);
        $this->assertSame(
            ['A Lua', 'A Estrela', 'O Sol'],
            $messages->pluck('card_name')->all(),
        );
        $this->assertSame(
            [$third->id, $card->id, $second->id],
            $messages->pluck('tarot_card_id')->all(),
        );

        foreach ($messages as $message) {
            $this->assertSame(MessageType::TarotCard, $message->type);
            $this->assertSame($attendant->id, $message->sender_id);
            $this->assertStringStartsWith("consultation-cards/{$consultation->id}/", $message->image_path);
            Storage::disk('tarot_private')->assertExists($message->image_path);
        }

        // O lote enviado junto compartilha um único `batch_id`, usado pelo chat
        // para desenhar as imagens lado a lado em um só bloco.
        $batchIds = $messages->pluck('batch_id')->unique();

        $this->assertCount(1, $batchIds);
        $this->assertNotNull($batchIds->first());
        $this->assertSame($batchIds->first(), $response->json('messages.0.batch_id'));
    }

    /**
     * Um envio de várias cartas vira um bloco único no histórico: o `batch_id`
     * gravado no servidor agrupa as imagens e o chat mostra o contador de
     * cartas em vez de repetir a mensagem para cada figura.
     */
    public function test_cards_sent_together_render_as_a_single_block_in_the_timeline(): void
    {
        [, $attendant, $consultation] = $this->conversation();
        [$card, $deck] = $this->card();
        $second = $this->cardInDeck($deck, 'O Sol');

        $batchId = $this->actingAs($attendant)
            ->postJson(route('consultations.cards.store', $consultation), [
                'tarot_card_ids' => [$card->id, $second->id],
            ])
            ->assertStatus(201)
            ->assertJsonCount(2, 'messages')
            ->json('messages.0.batch_id');

        $this->assertIsString($batchId);

        // Um novo envio abre outro lote: nada é reaproveitado do anterior.
        $otherBatchId = $this->actingAs($attendant)
            ->postJson(route('consultations.cards.store', $consultation), [
                'tarot_card_ids' => [$this->cardInDeck($deck, 'A Lua')->id],
            ])
            ->assertStatus(201)
            ->json('messages.0.batch_id');

        $this->assertIsString($otherBatchId);
        $this->assertNotSame($batchId, $otherBatchId);

        $response = $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-message-batch="'.$batchId.'"', false)
            ->assertSee('data-message-batch="'.$otherBatchId.'"', false)
            ->assertSee('2 cartas');

        $html = (string) $response->getContent();

        // Três cartas, duas mensagens na linha do tempo: o lote de duas imagens
        // e a carta enviada sozinha.
        $this->assertSame(2, substr_count($html, 'data-message-batch='));
        $this->assertSame(2, substr_count($html, 'class="chat-cards"'));
        $this->assertSame(1, substr_count($html, 'data-chat-cards-count'));
    }

    /**
     * Repetir uma carta ou passar do limite não pode gravar nada: o atendente
     * corrige a seleção e a consulta não fica com cartas pela metade.
     */
    public function test_card_batch_rejects_duplicates_and_more_than_the_limit(): void
    {
        [, $attendant, $consultation] = $this->conversation();
        [$card, $deck] = $this->card();
        $second = $this->cardInDeck($deck, 'O Sol');

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_ids' => [$card->id, $second->id, $card->id],
            ])
            ->assertSessionHasErrors('tarot_card_ids.2');

        TarotCard::factory()->count(ConsultationMessageManager::MAX_CARDS_PER_SEND)->create();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_ids' => TarotCard::query()->pluck('id')->all(),
            ])
            ->assertSessionHasErrors('tarot_card_ids');

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_ids' => [],
            ])
            ->assertSessionHasErrors('tarot_card_ids');

        $this->assertSame(0, ConsultationMessage::query()->count());
    }

    /**
     * O formato antigo (uma carta por requisição) continua funcionando, tanto
     * para integrações quanto para o formulário sem JavaScript.
     */
    public function test_single_card_payload_still_works(): void
    {
        [, $attendant, $consultation] = $this->conversation();
        [$card] = $this->card();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat')
            ->assertSessionHas('success', 'Carta enviada.');

        $this->assertSame(1, ConsultationMessage::query()->count());
    }

    /**
     * A grade do seletor serve miniaturas WebP geradas no servidor, e não o
     * arquivo original de cada carta: com cinco baralhos ativos a tela pinta
     * centenas de imagens e o original custava ~50 KB por célula.
     */
    public function test_card_picker_uses_webp_thumbnails_and_keeps_the_full_image_route(): void
    {
        [, $attendant, $consultation] = $this->conversation();
        [$card] = $this->card();

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('miniatura?v=', false)
            ->assertSee('data-card-image="'.route('tarot.cards.image', $card), false);

        $thumbnail = $this->actingAs($attendant)
            ->get(route('tarot.cards.thumb', $card))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('immutable', (string) $thumbnail->headers->get('Cache-Control'));
        $this->assertNotNull($thumbnail->headers->get('ETag'));

        // O WebP fica gravado ao lado do original: a próxima abertura do seletor
        // não repete a redução.
        $this->assertTrue(
            Storage::disk('tarot_private')->exists('cards/thumbnails/'.pathinfo((string) $card->image_path, PATHINFO_FILENAME).'.webp'),
        );

        // A autorização continua a mesma da imagem cheia.
        $this->actingAs(User::factory()->create())
            ->get(route('tarot.cards.thumb', $card))
            ->assertForbidden();
    }

    /**
     * Emoji no compositor: o seletor aparece para os dois participantes e uma
     * mensagem só de emoji é aceita e gravada como qualquer texto.
     */
    public function test_emoji_picker_is_available_and_emoji_only_message_is_accepted(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        foreach ([$client, $attendant] as $participant) {
            $this->actingAs($participant)
                ->get(route('consultations.show', $consultation))
                ->assertOk()
                ->assertSee('data-chat-emoji-trigger', false)
                ->assertSee('data-chat-emoji-panel', false);
        }

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), ['body' => '🔮✨🌙'])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');

        $this->assertSame('🔮✨🌙', ConsultationMessage::query()->sole()->body);
    }
}
