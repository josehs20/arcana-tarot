<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Enums\MessageType;
use App\Events\ConsultationMessageSent;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mensagens de voz do atendimento por áudio.
 *
 * O binário é gravado no navegador (MediaRecorder) e enviado só depois da
 * confirmação do participante. O servidor decide caminho, extensão e mime,
 * guarda tudo no disco privado e entrega o áudio apenas pela rota autorizada.
 */
class ConsultationVoiceTest extends TestCase
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
    private function conversation(string $modality = 'audio'): array
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();

        $factory = match ($modality) {
            'audio' => Consultation::factory()->audio(),
            'video' => Consultation::factory()->video(),
            default => Consultation::factory(),
        };

        $consultation = $factory->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        return [$client, $attendant, $consultation];
    }

    /**
     * Cabeçalho EBML de um contêiner WebM, o mesmo que o Chrome envia em uma
     * gravação só de áudio. O `finfo` identifica o arquivo como `video/webm`,
     * exatamente como acontece em produção.
     */
    private function webmBytes(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04"
            ."\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02"
            .str_repeat("\x00", 256);
    }

    private function recording(string $name = 'gravacao.webm'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->webmBytes());
    }

    public function test_participants_can_send_and_play_voice_messages(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $response = $this->actingAs($client)
            ->post(
                route('consultations.messages.audio.store', $consultation),
                [
                    'audio' => $this->recording(),
                    'duration' => 7,
                    'mime_type' => 'audio/webm',
                ],
                ['Accept' => 'application/json'],
            )
            ->assertCreated()
            ->assertJsonPath('message.type', MessageType::Voice->value)
            ->assertJsonPath('message.body', null)
            ->assertJsonPath('message.audio_duration_seconds', 7)
            ->assertJsonPath('message.audio_duration_label', '0:07')
            ->assertJsonPath('notice', 'Áudio enviado.');

        $message = ConsultationMessage::query()->sole();

        $this->assertSame($consultation->id, $message->consultation_id);
        $this->assertSame($client->id, $message->sender_id);
        $this->assertSame(MessageType::Voice, $message->type);
        $this->assertNull($message->body);
        $this->assertSame(7, $message->audio_duration_seconds);
        // O caminho é sempre gerado no servidor, dentro da pasta da consulta.
        $this->assertStringStartsWith('consultation-voice/'.$consultation->id.'/', (string) $message->audio_path);
        $this->assertStringEndsWith('.webm', (string) $message->audio_path);
        Storage::disk('tarot_private')->assertExists($message->audio_path);

        $audioUrl = route('consultations.messages.audio', [$consultation, $message]);

        $this->assertSame($audioUrl, $response->json('message.audio_url'));

        // Quem enviou continua ouvindo, e o outro lado da consulta também.
        foreach ([$client, $attendant] as $participant) {
            $this->actingAs($participant)
                ->get($audioUrl)
                ->assertOk()
                ->assertHeader('Content-Type', 'audio/webm')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee($audioUrl)
            ->assertSee('Mensagem de voz');
    }

    public function test_voice_message_is_persisted_before_broadcast_with_safe_payload(): void
    {
        [$client, , $consultation] = $this->conversation();

        Event::fake([ConsultationMessageSent::class]);

        $this->actingAs($client)
            ->post(route('consultations.messages.audio.store', $consultation), [
                'audio' => $this->recording(),
                'duration' => 12,
                'mime_type' => 'audio/webm',
            ])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');

        $message = ConsultationMessage::query()->sole();

        Event::assertDispatched(ConsultationMessageSent::class, function (ConsultationMessageSent $event) use ($message, $consultation): bool {
            $payload = $event->message;

            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('private-consultation.'.$consultation->id, $event->broadcastOn()[0]->name);
            $this->assertSame($message->id, $payload['id']);
            $this->assertSame('voice', $payload['type']);
            $this->assertNull($payload['body']);
            $this->assertSame(12, $payload['audio_duration_seconds']);
            $this->assertSame('0:12', $payload['audio_duration_label']);
            $this->assertSame(
                route('consultations.messages.audio', [$consultation, $message]),
                $payload['audio_url'],
            );
            // O caminho no disco privado nunca vai para o navegador.
            $this->assertArrayNotHasKey('audio_path', $payload);

            return true;
        });
    }

    public function test_voice_recordings_stay_private_to_the_consultation_participants(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->post(route('consultations.messages.audio.store', $consultation), [
                'audio' => $this->recording(),
                'duration' => 3,
            ])
            ->assertRedirect();

        $message = ConsultationMessage::query()->sole();
        $audioUrl = route('consultations.messages.audio', [$consultation, $message]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get($audioUrl)->assertForbidden();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get($audioUrl)->assertForbidden();

        // Outro participante de outra consulta não alcança esta gravação.
        $otherClient = User::factory()->create();
        $otherAttendant = User::factory()->attendant()->create();
        $otherConsultation = Consultation::factory()->audio()->active()->create([
            'client_id' => $otherClient->id,
            'attendant_id' => $otherAttendant->id,
        ]);

        $this->actingAs($otherClient)
            ->get(route('consultations.messages.audio', [$otherConsultation, $message]))
            ->assertNotFound();

        // A rota é exclusiva de mensagens de voz.
        $textMessage = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Mensagem de texto',
        ]);

        $this->actingAs($client)
            ->get(route('consultations.messages.audio', [$consultation, $textMessage]))
            ->assertNotFound();
    }

    public function test_voice_upload_requires_a_supported_recording_within_limits(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.messages.audio.store', $consultation), [])
            ->assertSessionHasErrors('audio');

        $this->actingAs($client)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.messages.audio.store', $consultation), [
                'audio' => UploadedFile::fake()->createWithContent('anotacao.txt', 'isto não é áudio'),
                'mime_type' => 'text/plain',
            ])
            ->assertSessionHasErrors('audio');

        $this->actingAs($client)
            ->from(route('consultations.show', $consultation))
            ->post(route('consultations.messages.audio.store', $consultation), [
                'audio' => $this->recording(),
                'duration' => (int) config('consultation.voice.max_seconds') + 1,
            ])
            ->assertSessionHasErrors('duration');

        $this->assertSame(0, ConsultationMessage::query()->count());
        $this->assertSame([], Storage::disk('tarot_private')->allFiles());
    }

    public function test_voice_messages_are_rejected_outside_the_audio_modality_and_after_the_end(): void
    {
        [$textClient, , $textConsultation] = $this->conversation('text');

        $this->actingAs($textClient)
            ->post(route('consultations.messages.audio.store', $textConsultation), [
                'audio' => $this->recording(),
                'duration' => 5,
            ])
            ->assertForbidden();

        [$videoClient, , $videoConsultation] = $this->conversation('video');

        $this->actingAs($videoClient)
            ->post(route('consultations.messages.audio.store', $videoConsultation), [
                'audio' => $this->recording(),
                'duration' => 5,
            ])
            ->assertForbidden();

        [$audioClient, , $audioConsultation] = $this->conversation();

        $audioConsultation->update([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $this->actingAs($audioClient)
            ->post(route('consultations.messages.audio.store', $audioConsultation), [
                'audio' => $this->recording(),
                'duration' => 5,
            ])
            ->assertForbidden();

        $this->assertSame(0, ConsultationMessage::query()->count());
        $this->assertSame([], Storage::disk('tarot_private')->allFiles());
    }

    /**
     * O compositor do áudio é o mesmo do chat: o botão de gravar fica ao lado
     * do envio e o texto digitado continua disponível.
     */
    public function test_audio_consultation_renders_the_voice_button_beside_the_send_button(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $voiceMax = (string) (int) config('consultation.voice.max_seconds');

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-chat-voice-form', false)
            ->assertSee('data-voice-url="'.route('consultations.messages.audio.store', $consultation).'"', false)
            ->assertSee('data-max-seconds="'.$voiceMax.'"', false)
            ->assertSee('Gravar áudio')
            ->assertSee('data-chat-submit-label', false)
            ->assertSee('Enviar mensagem');

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-chat-voice-form', false);

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'Texto enviado pelo chat do atendimento por áudio.',
            ])
            ->assertRedirect();

        $this->assertSame(1, ConsultationMessage::query()->count());
    }
}
