<?php

namespace Tests\Feature;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_for_returns_the_open_consultation_of_both_participants(): void
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $inbox = app(ConsultationInbox::class);

        $this->assertSame($consultation->id, $inbox->openFor($client)?->id);
        $this->assertSame($consultation->id, $inbox->openFor($attendant)?->id);
        $this->assertNull($inbox->openFor(User::factory()->create()));
    }

    public function test_open_for_ignores_final_consultations(): void
    {
        $client = User::factory()->create();

        Consultation::factory()->create([
            'client_id' => $client->id,
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
        ]);

        $this->assertNull(app(ConsultationInbox::class)->openFor($client));
    }

    public function test_pending_for_attendant_returns_only_requested_oldest_first(): void
    {
        $attendant = User::factory()->attendant()->create();

        $older = Consultation::factory()->create(['attendant_id' => $attendant->id]);
        $this->travel(1)->minutes();
        $newer = Consultation::factory()->create(['attendant_id' => $attendant->id]);
        $this->travel(1)->minutes();

        Consultation::factory()->active()->create(['attendant_id' => $attendant->id]);

        $otherAttendant = User::factory()->attendant()->create();
        Consultation::factory()->create(['attendant_id' => $otherAttendant->id]);

        $requests = app(ConsultationInbox::class)->pendingForAttendant($attendant);

        $this->assertSame([$older->id, $newer->id], $requests->pluck('id')->all());
        $this->assertTrue($requests->first()->relationLoaded('client'));
        $this->assertTrue($requests->first()->attendant->relationLoaded('attendantProfile'));
    }
}
