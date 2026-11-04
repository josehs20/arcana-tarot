<?php

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Canal privado da consulta.
 *
 * Somente cliente e atendente escalados, com conta ativa, podem ouvir
 * mensagens e mudanças de estado. O administrador não recebe permissão
 * explícita para a conversa (D-023/Q-06); qualquer acesso futuro exigirá uma
 * nova decisão e auditoria.
 */
Broadcast::channel('consultation.{consultation}', function (User $user, string $consultation): bool {
    if (! $user->is_active) {
        return false;
    }

    $model = Consultation::query()->find($consultation);

    return $model !== null && $model->hasParticipant($user);
});

/*
 * Canal privado do usuário.
 *
 * Usado para avisos pessoais que não pertencem a uma consulta específica,
 * como a chegada de uma nova solicitação de atendimento. Cada usuário só
 * pode ouvir o próprio canal; contas inativas não recebem autorização.
 *
 * O curinga se chama `userId` de propósito: um curinga chamado `user` faria
 * o Laravel tentar o binding implícito do modelo no parâmetro autenticado
 * `$user` e a autorização seria negada.
 */
Broadcast::channel('user.{userId}', function (User $user, string $userId): bool {
    return $user->is_active
        && $user->getKey() !== null
        && (int) $user->getKey() === (int) $userId;
});
