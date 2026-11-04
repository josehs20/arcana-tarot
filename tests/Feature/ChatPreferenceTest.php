<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preferências pessoais de conversa: envio com Enter e aviso sonoro.
 *
 * O servidor é a fonte de verdade — o bloco `#chat` publica os valores em
 * `data-*` e o JavaScript apenas lê e regrava sem recarregar a página. Estes
 * testes cobrem o padrão dos novos usuários, a renderização a partir do perfil
 * e a persistência pela rota `preferences.chat.update`.
 */
class ChatPreferenceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_new_users_start_with_enter_to_send_and_the_message_sound_enabled(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-send-on-enter="1"', false)
            ->assertSee('data-sound-enabled="1"', false)
            ->assertSee('data-preferences-url="'.route('preferences.chat.update').'"', false)
            ->assertSee('data-chat-preference="send_on_enter"', false)
            ->assertSee('data-chat-preference="sound_enabled"', false)
            ->assertSee('Enter envia · Shift+Enter quebra linha.');
    }

    public function test_chat_block_reflects_the_preferences_stored_in_the_profile(): void
    {
        [$client, , $consultation] = $this->conversation();

        $client->forceFill([
            'chat_send_on_enter' => false,
            'chat_sound_enabled' => false,
        ])->save();

        $this->actingAs($client->fresh())
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-send-on-enter="0"', false)
            ->assertSee('data-sound-enabled="0"', false)
            ->assertSee('Enter quebra linha · Ctrl+Enter envia.')
            ->assertDontSee('Enter envia · Shift+Enter quebra linha.');
    }

    public function test_authenticated_user_can_persist_both_preferences(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->patchJson(route('preferences.chat.update'), [
                'send_on_enter' => false,
                'sound_enabled' => false,
            ])
            ->assertOk()
            ->assertJson([
                'send_on_enter' => false,
                'sound_enabled' => false,
            ]);

        $client->refresh();

        $this->assertFalse($client->chat_send_on_enter);
        $this->assertFalse($client->chat_sound_enabled);
    }

    public function test_preferences_can_be_saved_without_javascript(): void
    {
        $client = User::factory()->create([
            'chat_send_on_enter' => false,
            'chat_sound_enabled' => false,
        ]);

        $this->actingAs($client)
            ->patch(route('preferences.chat.update'), [
                'send_on_enter' => true,
                'sound_enabled' => true,
            ])
            ->assertRedirect();

        $client->refresh();

        $this->assertTrue($client->chat_send_on_enter);
        $this->assertTrue($client->chat_sound_enabled);
    }

    public function test_preferences_must_be_boolean(): void
    {
        $client = User::factory()->create([
            'chat_send_on_enter' => true,
            'chat_sound_enabled' => true,
        ]);

        $this->actingAs($client)
            ->patchJson(route('preferences.chat.update'), [
                'send_on_enter' => 'talvez',
                'sound_enabled' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['send_on_enter', 'sound_enabled']);

        $client->refresh();

        $this->assertTrue($client->chat_send_on_enter);
        $this->assertTrue($client->chat_sound_enabled);
    }

    public function test_guests_cannot_persist_chat_preferences(): void
    {
        $this->patchJson(route('preferences.chat.update'), [
            'send_on_enter' => false,
            'sound_enabled' => false,
        ])->assertUnauthorized();
    }
}
