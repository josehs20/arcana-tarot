<?php

use App\Models\Consultation;
use App\Models\SupportConversation;
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

/*
 * Canal privado da conversa de suporte.
 *
 * Dois públicos usam o mesmo nome de canal:
 * - visitante anônimo: o endpoint /suporte/transmissao/autorizacao resolve o
 *   "usuário" para a conversa após validar o cookie e o hash do token;
 * - administrador: sessão web normal, conta ativa, papel admin.
 *
 * O visitante só ouve a própria conversa; nenhum outro visitante recebe a
 * autorização, mesmo sabendo o UUID.
 */
Broadcast::channel('support.conversation.{uuid}', function (User|SupportConversation $user, string $uuid): bool {
    if ($user instanceof SupportConversation) {
        return hash_equals($user->uuid, $uuid) && $user->isOpen();
    }

    return $user->is_active && $user->isAdmin();
});

/*
 * Canal privado da caixa de entrada: todos os administradores ativos recebem
 * novas conversas e mensagens para atualizar as abas do widget.
 */
Broadcast::channel('support.inbox', function (User $user): bool {
    return $user->is_active && $user->isAdmin();
});
