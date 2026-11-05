<?php

namespace Tests\Feature;

use App\Enums\MessageType;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
    }

    private function activeConversation(): array
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        return [$client, $attendant, $consultation];
    }

    public function test_message_and_catalog_image_routes_reject_paths_outside_allowed_directories(): void
    {
        [$client, $attendant, $consultation] = $this->activeConversation();

        $message = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'type' => MessageType::TarotCard->value,
            'body' => null,
            'deck_name' => 'Baralho',
            'card_name' => 'Carta',
            'image_path' => 'consultation-cards/'.$consultation->id.'/../../.env',
        ]);

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $message]))
            ->assertNotFound();

        $deck = TarotDeck::factory()->create(['is_active' => true]);
        $card = TarotCard::factory()->create([
            'deck_id' => $deck->id,
            'image_path' => '../.env',
            'image_mime' => 'image/png',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('tarot.cards.image', $card))
            ->assertNotFound();
    }

    public function test_consultation_request_creation_is_rate_limited_per_client(): void
    {
        $client = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => 10_000,
        ]);
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        $this->actingAs($client);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post(route('client.consultations.store'), [
                'attendant_id' => $attendant->id,
            ]);
        }

        $this->post(route('client.consultations.store'), [
            'attendant_id' => $attendant->id,
        ])->assertStatus(429);
    }
}
