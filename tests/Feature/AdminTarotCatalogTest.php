<?php

namespace Tests\Feature;

use App\Enums\ConsultationStatus;
use App\Enums\MessageType;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTarotCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function deck(array $attributes = []): TarotDeck
    {
        return TarotDeck::factory()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(TarotDeck $deck, array $attributes = []): TarotCard
    {
        return TarotCard::factory()->create([
            'deck_id' => $deck->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function deckPayload(array $overrides = []): array
    {
        return [
            'name' => 'Tarot de Marselha',
            'slug' => 'tarot-de-marselha',
            'description' => 'Baralho tradicional para leituras de autoconhecimento.',
            'is_active' => '1',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cardPayload(array $overrides = []): array
    {
        return [
            'name' => 'A Estrela',
            'sort_order' => 1,
            'meaning_upright' => 'Esperança, inspiração e renovação.',
            'meaning_reversed' => 'Desânimo e falta de direção.',
            'is_active' => '1',
            ...$overrides,
        ];
    }

    private function image(string $name = 'carta.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 300, 450);
    }

    /**
     * Cria uma carta com uma imagem realmente presente no disco privado.
     */
    private function cardWithImage(TarotDeck $deck, array $attributes = []): TarotCard
    {
        $card = $this->card($deck, $attributes);
        $this->attachImage($card);

        return $card->fresh();
    }

    private function attachImage(TarotCard $card, string $originalName = 'carta.png'): string
    {
        $file = $this->image($originalName);
        $path = 'cards/'.Str::random(40).'.png';

        Storage::disk('tarot_private')->putFileAs('cards', $file, basename($path));

        $checksum = hash_file('sha256', $file->getRealPath());

        $card->forceFill([
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_size' => (int) $file->getSize(),
            'image_checksum' => $checksum !== false ? $checksum : '',
        ])->save();

        return $path;
    }

    public function test_guest_is_redirected_to_login_on_every_catalog_route(): void
    {
        $deck = $this->deck();
        $card = $this->card($deck);

        $requests = [
            ['get', route('admin.decks.index')],
            ['get', route('admin.decks.create')],
            ['post', route('admin.decks.store')],
            ['get', route('admin.decks.edit', $deck)],
            ['patch', route('admin.decks.update', $deck)],
            ['patch', route('admin.decks.status', $deck)],
            ['get', route('admin.decks.cards.index', $deck)],
            ['get', route('admin.decks.cards.create', $deck)],
            ['post', route('admin.decks.cards.store', $deck)],
            ['get', route('admin.decks.cards.edit', [$deck, $card])],
            ['patch', route('admin.decks.cards.update', [$deck, $card])],
            ['patch', route('admin.decks.cards.status', [$deck, $card])],
            ['patch', route('admin.decks.cards.move', [$deck, $card])],
            ['get', route('tarot.cards.image', $card)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
    }

    public function test_non_admin_and_inactive_admin_cannot_manage_catalog(): void
    {
        $deck = $this->deck();
        $card = $this->card($deck);

        $users = [
            User::factory()->create(),
            User::factory()->attendant()->create(),
            User::factory()->admin()->create(['is_active' => false]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('admin.decks.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.decks.create'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.decks.cards.index', $deck))->assertForbidden();
            $this->actingAs($user)->post(route('admin.decks.store'), $this->deckPayload())->assertForbidden();
            $this->actingAs($user)->patch(route('admin.decks.status', $deck), ['is_active' => 0])->assertForbidden();
            $this->actingAs($user)->patch(route('admin.decks.cards.status', [$deck, $card]), ['is_active' => 0])->assertForbidden();
            $this->actingAs($user)->patch(route('admin.decks.cards.move', [$deck, $card]), ['direction' => 'up'])->assertForbidden();
        }

        $this->assertTrue($deck->fresh()->is_active);
        $this->assertTrue($card->fresh()->is_active);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_admin_can_list_and_filter_decks(): void
    {
        $admin = $this->admin();

        $marselha = $this->deck(['name' => 'Tarot de Marselha', 'slug' => 'tarot-de-marselha']);
        $this->card($marselha, ['is_active' => true]);
        $this->card($marselha, ['is_active' => false]);

        $this->deck([
            'name' => 'Baralho Arquivado',
            'slug' => 'baralho-arquivado',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.decks.index'))
            ->assertOk()
            ->assertSee('Tarot de Marselha')
            ->assertSee('Baralho Arquivado')
            ->assertSee('1 ativas')
            ->assertSee(route('admin.decks.cards.index', $marselha), false);

        $this->actingAs($admin)
            ->get(route('admin.decks.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Tarot de Marselha')
            ->assertDontSee('Baralho Arquivado');

        $this->actingAs($admin)
            ->get(route('admin.decks.index', ['q' => 'Arquivado']))
            ->assertOk()
            ->assertSee('Baralho Arquivado')
            ->assertDontSee('Tarot de Marselha');
    }

    public function test_admin_can_create_deck_and_action_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.decks.store'), $this->deckPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $deck = TarotDeck::query()->firstOrFail();

        $this->assertSame('Tarot de Marselha', $deck->name);
        $this->assertSame('tarot-de-marselha', $deck->slug);
        $this->assertTrue($deck->is_active);

        $log = AuditLog::query()->where('action', 'tarot_deck.created')->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($deck->getMorphClass(), $log->subject_type);
        $this->assertSame($deck->id, $log->subject_id);
        $this->assertSame('tarot-de-marselha', $log->metadata['slug']);
    }

    public function test_deck_validation_rejects_duplicate_slug_and_empty_name(): void
    {
        $admin = $this->admin();
        $this->deck(['slug' => 'tarot-de-marselha']);

        $this->actingAs($admin)
            ->post(route('admin.decks.store'), $this->deckPayload([
                'name' => '',
                'slug' => 'Tarot de Marselha',
            ]))
            ->assertSessionHasErrors(['name', 'slug']);

        $this->assertSame(1, TarotDeck::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_admin_can_edit_deck_and_change_status_without_deleting_cards(): void
    {
        $admin = $this->admin();
        $deck = $this->deck(['name' => 'Nome Antigo']);
        $card = $this->card($deck);

        $this->actingAs($admin)
            ->patch(route('admin.decks.update', $deck), $this->deckPayload([
                'name' => 'Nome Novo',
                'slug' => 'nome-novo',
                'description' => '',
            ]))
            ->assertRedirect(route('admin.decks.edit', $deck))
            ->assertSessionHas('success');

        $deck->refresh();
        $this->assertSame('Nome Novo', $deck->name);
        $this->assertNull($deck->description);
        $this->assertSame(1, AuditLog::query()->where('action', 'tarot_deck.updated')->count());

        $this->actingAs($admin)
            ->patch(route('admin.decks.status', $deck), ['is_active' => 0])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($deck->fresh()->is_active);
        $this->assertDatabaseHas('tarot_cards', ['id' => $card->id]);

        $log = AuditLog::query()->where('action', 'tarot_deck.status_changed')->firstOrFail();
        $this->assertSame(['from' => true, 'to' => false], $log->metadata);
    }

    public function test_cards_list_is_scoped_to_deck_and_links_private_preview(): void
    {
        $admin = $this->admin();
        $deck = $this->deck(['name' => 'Baralho Principal']);
        $otherDeck = $this->deck(['name' => 'Outro Baralho']);

        $star = $this->cardWithImage($deck, ['name' => 'A Estrela', 'sort_order' => 2]);
        $this->cardWithImage($deck, ['name' => 'O Sol', 'sort_order' => 1]);
        $this->card($otherDeck, ['name' => 'Carta de Outro Baralho']);

        $this->actingAs($admin)
            ->get(route('admin.decks.cards.index', $deck))
            ->assertOk()
            ->assertSee('A Estrela')
            ->assertSee('O Sol')
            ->assertDontSee('Carta de Outro Baralho')
            ->assertSee(route('tarot.cards.image', $star), false)
            ->assertSee('2 de 2 cartas ativas');
    }

    public function test_admin_creates_card_with_secure_random_image_path_and_audit(): void
    {
        $admin = $this->admin();
        $deck = $this->deck();

        // O nome enviado pelo navegador não deve ser usado no storage.
        $this->actingAs($admin)
            ->post(route('admin.decks.cards.store', $deck), $this->cardPayload([
                'image' => $this->image('minha-carta.png'),
            ]), ['Accept' => 'text/html'])
            ->assertRedirect(route('admin.decks.cards.index', $deck))
            ->assertSessionHas('success');

        $card = TarotCard::query()->firstOrFail();

        $this->assertSame('A Estrela', $card->name);
        $this->assertSame('a-estrela', $card->slug);
        $this->assertSame($deck->id, $card->deck_id);
        $this->assertStringStartsWith('cards/', $card->image_path);
        $this->assertStringNotContainsString('minha-carta', $card->image_path);
        $this->assertContains(pathinfo($card->image_path, PATHINFO_EXTENSION), ['png', 'webp']);
        $this->assertContains($card->image_mime, ['image/png', 'image/webp']);
        $this->assertGreaterThan(0, $card->image_size);
        $this->assertSame(64, strlen($card->image_checksum));
        $this->assertTrue(Storage::disk('tarot_private')->exists($card->image_path));

        // O checksum e o tamanho gravados descrevem o arquivo armazenado, e não
        // o upload original: a imagem é normalizada antes da gravação.
        $stored = (string) Storage::disk('tarot_private')->get($card->image_path);
        $this->assertSame(strlen($stored), $card->image_size);
        $this->assertSame(hash('sha256', $stored), $card->image_checksum);

        $absolutePath = Storage::disk('tarot_private')->path($card->image_path);
        $this->assertFalse(str_starts_with($absolutePath, public_path().DIRECTORY_SEPARATOR));
        $this->assertFileDoesNotExist(public_path($card->image_path));

        $log = AuditLog::query()->where('action', 'tarot_card.created')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($card->image_path, $log->metadata['image_path']);
        $this->assertArrayNotHasKey('contents', $log->metadata);
    }

    public function test_card_creation_rejects_missing_svg_executable_and_oversized_image(): void
    {
        $admin = $this->admin();
        $deck = $this->deck();

        $this->actingAs($admin)
            ->post(route('admin.decks.cards.store', $deck), $this->cardPayload())
            ->assertSessionHasErrors('image');

        $this->actingAs($admin)
            ->post(route('admin.decks.cards.store', $deck), $this->cardPayload([
                'image' => UploadedFile::fake()->createWithContent(
                    'carta.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            ]))
            ->assertSessionHasErrors('image');

        $this->actingAs($admin)
            ->post(route('admin.decks.cards.store', $deck), $this->cardPayload([
                'image' => UploadedFile::fake()->createWithContent('carta.php', '<?php echo "x";'),
            ]))
            ->assertSessionHasErrors('image');

        config(['images.max_upload_kb' => 1]);

        $this->actingAs($admin)
            ->post(route('admin.decks.cards.store', $deck), $this->cardPayload([
                'image' => UploadedFile::fake()->image('grande.png', 900, 900),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, TarotCard::query()->count());
        $this->assertSame([], Storage::disk('tarot_private')->allFiles());
    }

    public function test_card_update_without_image_keeps_current_file(): void
    {
        $admin = $this->admin();
        $deck = $this->deck();
        $card = $this->cardWithImage($deck);
        $path = $card->image_path;
        $checksum = $card->image_checksum;

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.update', [$deck, $card]), $this->cardPayload([
                'name' => 'A Estrela Renovada',
                'meaning_upright' => 'Novo significado normal.',
            ]))
            ->assertRedirect(route('admin.decks.cards.edit', [$deck, $card]))
            ->assertSessionHas('success');

        $card->refresh();

        $this->assertSame('A Estrela Renovada', $card->name);
        $this->assertSame('a-estrela-renovada', $card->slug);
        $this->assertSame($path, $card->image_path);
        $this->assertSame($checksum, $card->image_checksum);
        $this->assertTrue(Storage::disk('tarot_private')->exists($path));
        $this->assertSame(0, AuditLog::query()->where('action', 'tarot_card.image_replaced')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'tarot_card.updated')->count());
    }

    public function test_replacing_card_image_keeps_previous_file_and_historical_message(): void
    {
        $admin = $this->admin();
        $deck = $this->deck(['name' => 'Baralho Histórico']);
        $card = $this->cardWithImage($deck, ['name' => 'A Lua']);
        $oldPath = $card->image_path;

        $consultation = Consultation::factory()->active()->create([
            'client_id' => User::factory()->create()->id,
        ]);

        $message = ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $consultation->attendant_id,
            'type' => MessageType::TarotCard->value,
            'body' => null,
            'tarot_card_id' => $card->id,
            'deck_name' => $deck->name,
            'card_name' => $card->name,
            'image_path' => $oldPath,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.update', [$deck, $card]), $this->cardPayload([
                'name' => 'A Lua',
                'image' => $this->image('nova-lua.png'),
            ]))
            ->assertRedirect(route('admin.decks.cards.edit', [$deck, $card]))
            ->assertSessionHas('success');

        $card->refresh();

        $this->assertNotSame($oldPath, $card->image_path);
        $this->assertTrue(Storage::disk('tarot_private')->exists($oldPath));
        $this->assertTrue(Storage::disk('tarot_private')->exists($card->image_path));
        $this->assertSame($oldPath, $message->fresh()->image_path);
        $this->assertSame($card->id, $message->fresh()->tarot_card_id);
        $this->assertSame($deck->name, $message->fresh()->deck_name);
        $this->assertSame('A Lua', $message->fresh()->card_name);

        $log = AuditLog::query()->where('action', 'tarot_card.image_replaced')->firstOrFail();

        $this->assertSame($oldPath, $log->metadata['from_path']);
        $this->assertSame($card->image_path, $log->metadata['to_path']);
        $this->assertArrayNotHasKey('contents', $log->metadata);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', json_encode($log->metadata));
    }

    public function test_admin_can_reorder_cards_and_order_is_audited(): void
    {
        $admin = $this->admin();
        $deck = $this->deck();

        $first = $this->card($deck, ['name' => 'Primeira', 'sort_order' => 1]);
        $second = $this->card($deck, ['name' => 'Segunda', 'sort_order' => 2]);
        $third = $this->card($deck, ['name' => 'Terceira', 'sort_order' => 3]);

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.move', [$deck, $second]), ['direction' => 'up'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            ['Segunda', 'Primeira', 'Terceira'],
            $deck->cards()->orderBy('sort_order')->pluck('name')->all(),
        );

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(3, $third->fresh()->sort_order);

        $log = AuditLog::query()->where('action', 'tarot_card.reordered')->firstOrFail();
        $this->assertSame(['deck_id' => $deck->id, 'from' => 2, 'to' => 1], $log->metadata);

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.move', [$deck, $second]), ['direction' => 'up'])
            ->assertRedirect()
            ->assertSessionHas('info');
    }

    public function test_card_and_deck_deactivation_preserve_records_and_selector_scope(): void
    {
        $admin = $this->admin();
        $activeDeck = $this->deck(['name' => 'Ativo', 'is_active' => true]);
        $inactiveDeck = $this->deck(['name' => 'Inativo', 'is_active' => false]);

        $available = $this->cardWithImage($activeDeck, ['name' => 'Disponível', 'is_active' => true]);
        $inactiveCard = $this->cardWithImage($inactiveDeck, ['name' => 'Oculta pelo baralho', 'is_active' => true]);

        $this->assertSame(
            [$available->id],
            TarotCard::query()->availableForSelection()->pluck('id')->all(),
        );
        $this->assertSame(
            [$activeDeck->id],
            TarotDeck::query()->active()->pluck('id')->all(),
        );

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.status', [$activeDeck, $available]), ['is_active' => 0])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($available->fresh()->is_active);
        $this->assertTrue(Storage::disk('tarot_private')->exists($available->image_path));
        $this->assertSame(0, TarotCard::query()->availableForSelection()->count());

        $log = AuditLog::query()->where('action', 'tarot_card.status_changed')->firstOrFail();
        $this->assertSame(['deck_id' => $activeDeck->id, 'from' => true, 'to' => false], $log->metadata);

        $this->assertDatabaseHas('tarot_cards', ['id' => $inactiveCard->id]);
    }

    public function test_card_from_another_deck_returns_not_found_on_nested_routes(): void
    {
        $admin = $this->admin();
        $deck = $this->deck();
        $otherDeck = $this->deck();
        $card = $this->card($otherDeck);

        $this->actingAs($admin)
            ->get(route('admin.decks.cards.edit', [$deck, $card]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.status', [$deck, $card]), ['is_active' => 0])
            ->assertNotFound();

        $this->assertTrue($card->fresh()->is_active);
    }

    public function test_card_with_orphan_image_path_shows_placeholder_and_requires_new_upload(): void
    {
        $admin = $this->admin();
        $deck = $this->deck();
        $card = $this->card($deck, ['name' => 'Carta Sem Arquivo']);

        $this->assertFalse($card->hasStoredImage());

        $this->actingAs($admin)
            ->get(route('admin.decks.cards.index', $deck))
            ->assertOk()
            ->assertSee('Carta Sem Arquivo')
            ->assertDontSee(route('tarot.cards.image', $card), false);

        $this->actingAs($admin)
            ->get(route('admin.decks.cards.edit', [$deck, $card]))
            ->assertOk()
            ->assertDontSee('Imagem atual');

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.update', [$deck, $card]), $this->cardPayload([
                'name' => 'Carta Sem Arquivo',
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame($card->image_path, $card->fresh()->image_path);
    }

    public function test_admin_can_open_deck_and_card_forms(): void
    {
        $admin = $this->admin();
        $deck = $this->deck(['name' => 'Baralho Editável']);
        $card = $this->cardWithImage($deck, ['name' => 'Carta Editável']);

        $this->actingAs($admin)
            ->get(route('admin.decks.create'))
            ->assertOk()
            ->assertSee('Novo baralho')
            ->assertSee('Identificador (slug)')
            ->assertSee('Criar baralho');

        $this->actingAs($admin)
            ->get(route('admin.decks.edit', $deck))
            ->assertOk()
            ->assertSee('Baralho Editável')
            ->assertSee('Salvar baralho');

        $this->actingAs($admin)
            ->get(route('admin.decks.cards.create', $deck))
            ->assertOk()
            ->assertSee('Nova carta')
            ->assertSee('Imagem frontal')
            ->assertSee('JPEG, PNG ou WebP');

        $this->actingAs($admin)
            ->get(route('admin.decks.cards.edit', [$deck, $card]))
            ->assertOk()
            ->assertSee('Carta Editável')
            ->assertSee('Imagem atual')
            ->assertSee(route('tarot.cards.image', $card), false)
            ->assertSee('data-image-upload', false)
            ->assertSee('data-image-profile="tarot_card"', false)
            ->assertSee('data-image-max-bytes="26214400"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_admin_can_preview_any_card_without_exposing_storage_path(): void
    {
        $admin = $this->admin();
        $deck = $this->deck(['is_active' => false]);
        $card = $this->cardWithImage($deck, ['is_active' => false]);

        $response = $this->actingAs($admin)
            ->get(route('tarot.cards.image', $card))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', sprintf('inline; filename="carta-%d.png"', $card->id));

        $body = $response->streamedContent();

        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString($card->image_path, $body);
        $this->assertStringNotContainsString(Storage::disk('tarot_private')->path($card->image_path), $body);

        $this->actingAs(User::factory()->admin()->create(['is_active' => false]))
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();
    }

    public function test_catalog_preview_requires_active_consultation_participation_and_active_records(): void
    {
        $client = User::factory()->create();
        $attendant = User::factory()->attendant()->create();
        $outsider = User::factory()->create();

        $deck = $this->deck(['is_active' => true]);
        $card = $this->cardWithImage($deck, ['is_active' => true]);

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $this->actingAs($client)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $consultation->update([
            'status' => ConsultationStatus::Active,
            'started_at' => now(),
        ]);

        $this->actingAs($client)
            ->get(route('tarot.cards.image', $card))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($attendant)
            ->get(route('tarot.cards.image', $card))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($outsider)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $card->update(['is_active' => false]);

        $this->actingAs($client)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $card->update(['is_active' => true]);
        $deck->update(['is_active' => false]);

        $this->actingAs($client)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();
    }
}
