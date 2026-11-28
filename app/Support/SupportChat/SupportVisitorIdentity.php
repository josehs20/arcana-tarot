<?php

namespace App\Support\SupportChat;

use App\Models\SupportConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use JsonException;

/**
 * Identidade anônima do visitante do suporte.
 *
 * O cookie guarda um JSON com o UUID público da conversa e o token secreto.
 * O banco conhece apenas o hash do token: quem copia o cookie não consegue
 * descobrir o segredo a partir do banco, e o token nunca aparece em URLs.
 */
class SupportVisitorIdentity
{
    private const TOKEN_BYTES = 48;

    public function current(Request $request): ?SupportConversation
    {
        $payload = $this->payload($request);

        if ($payload === null) {
            return null;
        }

        $conversation = SupportConversation::query()
            ->where('uuid', $payload['uuid'])
            ->first();

        if ($conversation === null) {
            return null;
        }

        if (! hash_equals($conversation->visitor_token_hash, $this->hash($payload['token']))) {
            return null;
        }

        return $conversation;
    }

    public function require(Request $request): SupportConversation
    {
        return $this->current($request)
            ?? abort(403, 'Conversa de suporte não encontrada para este navegador.');
    }

    public function newToken(): string
    {
        return Str::random(self::TOKEN_BYTES);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function remember(SupportConversation $conversation, string $token): void
    {
        $payload = json_encode([
            'uuid' => $conversation->uuid,
            'token' => $token,
        ]);

        Cookie::queue(
            (string) config('support.cookie', 'arcana_support'),
            (string) $payload,
            max(1, (int) config('support.cookie_lifetime_days', 30)) * 24 * 60,
            '/',
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            'lax',
        );
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget((string) config('support.cookie', 'arcana_support')));
    }

    /**
     * @return array{uuid: string, token: string}|null
     */
    private function payload(Request $request): ?array
    {
        $raw = $request->cookie((string) config('support.cookie', 'arcana_support'));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $uuid = $decoded['uuid'] ?? null;
        $token = $decoded['token'] ?? null;

        if (! is_string($uuid) || ! is_string($token) || $token === '') {
            return null;
        }

        if (! Str::isUuid($uuid)) {
            return null;
        }

        return ['uuid' => $uuid, 'token' => $token];
    }
}
