<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Casos de uso do catálogo administrativo de baralhos e cartas.
 *
 * Toda alteração relevante gera um audit_log com metadados textuais, nunca com
 * o conteúdo binário da imagem. Imagens substituídas permanecem no disco para
 * preservar o histórico de mensagens que apontem para o caminho anterior.
 */
final class TarotCatalogManager
{
    public function __construct(private TarotImageStorage $images) {}

    /**
     * @param  array{name: string, slug: string, description: ?string, is_active: bool}  $data
     */
    public function createDeck(array $data, User $actor): TarotDeck
    {
        return DB::transaction(function () use ($data, $actor): TarotDeck {
            $deck = TarotDeck::query()->create($data);

            $this->audit($actor, 'tarot_deck.created', $deck, [
                'name' => $deck->name,
                'slug' => $deck->slug,
                'is_active' => $deck->is_active,
            ]);

            return $deck;
        });
    }

    /**
     * @param  array{name: string, slug: string, description: ?string, is_active: bool}  $data
     */
    public function updateDeck(TarotDeck $deck, array $data, User $actor): TarotDeck
    {
        return DB::transaction(function () use ($deck, $data, $actor): TarotDeck {
            $locked = TarotDeck::query()->lockForUpdate()->findOrFail($deck->getKey());
            $locked->fill($data);

            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $changes = $this->describeChanges($locked, $changed);
            $from = $locked->getOriginal('is_active');
            $locked->save();

            $metadata = ['changed' => $changed, 'changes' => $changes];

            if ($locked->wasChanged('is_active')) {
                $metadata['status'] = [
                    'from' => (bool) $from,
                    'to' => (bool) $locked->is_active,
                ];

                $this->audit($actor, 'tarot_deck.status_changed', $locked, $metadata['status']);
            }

            $this->audit($actor, 'tarot_deck.updated', $locked, $metadata);

            return $locked;
        });
    }

    public function setDeckActive(TarotDeck $deck, bool $active, User $actor): TarotDeck
    {
        return DB::transaction(function () use ($deck, $active, $actor): TarotDeck {
            $locked = TarotDeck::query()->lockForUpdate()->findOrFail($deck->getKey());

            if ($locked->is_active === $active) {
                return $locked;
            }

            $from = $locked->is_active;
            $locked->is_active = $active;
            $locked->save();

            $this->audit($actor, 'tarot_deck.status_changed', $locked, [
                'from' => $from,
                'to' => $active,
            ]);

            return $locked;
        });
    }

    /**
     * @param  array{name: string, sort_order: int, meaning_upright: ?string, meaning_reversed: ?string, is_active: bool}  $data
     */
    public function createCard(TarotDeck $deck, array $data, UploadedFile $image, User $actor): TarotCard
    {
        $storedImage = $this->images->store($image);

        try {
            return DB::transaction(function () use ($deck, $data, $storedImage, $actor): TarotCard {
                $card = $deck->cards()->create([
                    ...$data,
                    ...$storedImage,
                    'slug' => $this->uniqueCardSlug($deck, $data['name']),
                ]);

                $this->audit($actor, 'tarot_card.created', $card, [
                    'deck_id' => $deck->getKey(),
                    'name' => $card->name,
                    'sort_order' => $card->sort_order,
                    'is_active' => $card->is_active,
                    'image_path' => $card->image_path,
                    'image_mime' => $card->image_mime,
                    'image_size' => $card->image_size,
                ]);

                return $card;
            });
        } catch (Throwable $exception) {
            $this->images->discardNewFile($storedImage['image_path']);

            throw $exception;
        }
    }

    /**
     * @param  array{name: string, sort_order: int, meaning_upright: ?string, meaning_reversed: ?string, is_active: bool}  $data
     */
    public function updateCard(
        TarotDeck $deck,
        TarotCard $card,
        array $data,
        ?UploadedFile $image,
        User $actor,
    ): TarotCard {
        $storedImage = $image ? $this->images->store($image) : null;

        try {
            return DB::transaction(function () use ($deck, $card, $data, $storedImage, $actor): TarotCard {
                $locked = TarotCard::query()->lockForUpdate()->findOrFail($card->getKey());
                $previousImagePath = $locked->image_path;
                $previousStatus = $locked->is_active;

                $locked->fill($data);

                if ($locked->isDirty('name') || blank($locked->slug)) {
                    $locked->slug = $this->uniqueCardSlug($deck, $data['name'], $locked->getKey());
                }

                if ($storedImage !== null) {
                    $locked->fill($storedImage);
                }

                $changed = array_keys($locked->getDirty());

                if ($changed === []) {
                    return $locked;
                }

                $changes = $this->describeChanges($locked, $changed);
                $locked->save();

                $this->audit($actor, 'tarot_card.updated', $locked, [
                    'deck_id' => $deck->getKey(),
                    'changed' => $changed,
                    'changes' => $changes,
                ]);

                if ($locked->wasChanged('is_active')) {
                    $this->audit($actor, 'tarot_card.status_changed', $locked, [
                        'deck_id' => $deck->getKey(),
                        'from' => $previousStatus,
                        'to' => $locked->is_active,
                    ]);
                }

                if ($storedImage !== null) {
                    $this->audit($actor, 'tarot_card.image_replaced', $locked, [
                        'from_path' => $previousImagePath,
                        'to_path' => $storedImage['image_path'],
                        'mime' => $storedImage['image_mime'],
                        'size' => $storedImage['image_size'],
                    ]);
                }

                return $locked;
            });
        } catch (Throwable $exception) {
            if ($storedImage !== null) {
                $this->images->discardNewFile($storedImage['image_path']);
            }

            throw $exception;
        }
    }

    public function setCardActive(TarotDeck $deck, TarotCard $card, bool $active, User $actor): TarotCard
    {
        return DB::transaction(function () use ($deck, $card, $active, $actor): TarotCard {
            $locked = TarotCard::query()->lockForUpdate()->findOrFail($card->getKey());

            if ($locked->is_active === $active) {
                return $locked;
            }

            $from = $locked->is_active;
            $locked->is_active = $active;
            $locked->save();

            $this->audit($actor, 'tarot_card.status_changed', $locked, [
                'deck_id' => $deck->getKey(),
                'from' => $from,
                'to' => $active,
            ]);

            return $locked;
        });
    }

    public function moveCard(TarotDeck $deck, TarotCard $card, string $direction, User $actor): bool
    {
        return DB::transaction(function () use ($deck, $card, $direction, $actor): bool {
            $cards = TarotCard::query()
                ->where('deck_id', $deck->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $index = $cards->search(fn (TarotCard $item): bool => $item->is($card));
            $targetIndex = $index === false ? $index : ($direction === 'up' ? $index - 1 : $index + 1);

            if ($index === false || $targetIndex < 0 || $targetIndex >= $cards->count()) {
                return false;
            }

            $ordered = $cards->all();
            [$ordered[$index], $ordered[$targetIndex]] = [$ordered[$targetIndex], $ordered[$index]];

            foreach ($ordered as $position => $item) {
                if ($item->sort_order !== $position + 1) {
                    $item->sort_order = $position + 1;
                    $item->save();
                }
            }

            $this->audit($actor, 'tarot_card.reordered', $card, [
                'deck_id' => $deck->getKey(),
                'from' => $index + 1,
                'to' => $targetIndex + 1,
            ]);

            return true;
        });
    }

    /**
     * Registra os valores alterados (limitados) sem incluir campos de imagem,
     * que têm auditoria própria com caminhos e metadados.
     *
     * @param  array<int, string>  $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function describeChanges(Model $model, array $fields): array
    {
        $ignored = ['image_path', 'image_mime', 'image_size', 'image_checksum'];

        return collect($fields)
            ->reject(fn (string $field): bool => in_array($field, $ignored, true))
            ->mapWithKeys(fn (string $field): array => [
                $field => [
                    'from' => $this->auditValue($model->getOriginal($field)),
                    'to' => $this->auditValue($model->getAttribute($field)),
                ],
            ])
            ->all();
    }

    private function auditValue(mixed $value): mixed
    {
        if (! is_string($value) || mb_strlen($value) <= 200) {
            return $value;
        }

        return mb_substr($value, 0, 200).'…';
    }

    private function uniqueCardSlug(TarotDeck $deck, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'carta';
        $slug = $base;
        $suffix = 2;

        while (
            TarotCard::query()
                ->where('deck_id', $deck->getKey())
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $actor, string $action, Model $subject, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
