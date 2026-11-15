<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\User;
use App\Support\ConsultationMessageFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationMessageFeedTest extends TestCase
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

    private function message(Consultation $consultation, User $sender, string $body): ConsultationMessage
    {
        return ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
    }

    public function test_history_is_paginated_from_the_newest_message_and_shown_chronologically(): void
    {
        config(['consultation.message_page_size' => 2]);

        [$client, , $consultation] = $this->conversation();

        $this->message($consultation, $client, 'primeira');
        $this->travel(1)->minutes();
        $second = $this->message($consultation, $client, 'segunda');
        $this->travel(1)->minutes();
        $third = $this->message($consultation, $client, 'terceira');

        $page = app(ConsultationMessageFeed::class)->paginate($consultation);

        $this->assertSame(3, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertSame([$second->id, $third->id], $page->getCollection()->pluck('id')->all());
        $this->assertTrue($page->getCollection()->first()->relationLoaded('sender'));
    }

    public function test_unread_count_and_read_mark_follow_each_participant(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $feed = app(ConsultationMessageFeed::class);

        $consultation->markMessagesReadBy($client);

        $this->message($consultation, $client, 'oi');
        $this->travel(1)->minutes();
        $this->message($consultation, $attendant, 'olá');
        $this->travel(1)->minutes();
        $this->message($consultation, $attendant, 'tudo bem?');

        $this->assertSame(2, $feed->unreadCount($consultation->fresh(), $client));
        $this->assertSame(1, $feed->unreadCount($consultation->fresh(), $attendant));

        $feed->markRead($consultation, $client);

        $updated = $consultation->fresh();

        $this->assertNotNull($updated->client_last_read_at);
        $this->assertSame(0, $feed->unreadCount($updated, $client));
        $this->assertSame(1, $feed->unreadCount($updated, $attendant));
    }

    public function test_mark_read_ignores_users_outside_the_consultation(): void
    {
        [, , $consultation] = $this->conversation();

        $stranger = User::factory()->create();

        $feed = app(ConsultationMessageFeed::class);
        $feed->markRead($consultation, $stranger);

        $updated = $consultation->fresh();

        $this->assertNull($updated->client_last_read_at);
        $this->assertNull($updated->attendant_last_read_at);
        $this->assertSame(0, $feed->unreadCount($updated, $stranger));
    }
}
