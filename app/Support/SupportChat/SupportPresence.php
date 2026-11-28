<?php

namespace App\Support\SupportChat;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Disponibilidade da equipe de suporte.
 *
 * O painel bate o ponto enquanto a aba está visível; o visitante só vê
 * "online" quando existe ao menos um administrador ativo com batimento
 * recente. Não há tabela de presença: o cache é descartável por definição e a
 * mensagem continua persistida quando ninguém está online.
 */
class SupportPresence
{
    public function markOnline(User $user): void
    {
        Cache::put(
            $this->key($user),
            now()->getTimestamp(),
            max(30, (int) config('support.presence_ttl_seconds', 120)),
        );
    }

    public function isTeamOnline(): bool
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->pluck('id')
            ->contains(fn (int $userId): bool => Cache::has($this->keyForId($userId)));
    }

    /**
     * @return array{online: bool, label: string}
     */
    public function status(): array
    {
        $online = $this->isTeamOnline();

        return [
            'online' => $online,
            'label' => $online
                ? 'Equipe online — respondemos em instantes'
                : 'Equipe offline — deixe sua mensagem e respondemos por aqui',
        ];
    }

    private function key(User $user): string
    {
        return $this->keyForId((int) $user->getKey());
    }

    private function keyForId(int $userId): string
    {
        return 'support:presence:'.$userId;
    }
}
