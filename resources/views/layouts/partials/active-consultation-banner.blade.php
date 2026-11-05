{{--
    Aviso de atendimento em andamento fora da tela de atendimento.

    O workspace já avisa quando a pessoa sai do chat. Enquanto ela navega por
    outras telas do painel — histórico, carteira, perfil, recibo ou a tela de
    uma consulta — a consulta continua ativa e sendo cobrada, e não havia
    caminho de volta a não ser lembrar o endereço. Este bloco mostra o aviso e
    o botão **Voltar ao atendimento**, sempre montado no servidor com a consulta
    ativa do próprio usuário (`ActiveConsultationResolver`).

    O elemento também existe para `resources/js/user-channel.js`, que o esconde
    quando o canal privado informa o encerramento e reaponta o link se outra
    consulta ficar ativa. Nenhuma decisão de cobrança acontece aqui.
--}}
@php
    $returnUser = auth()->user();
    $returnCounterparty = $consultation === null
        ? null
        : (
            $consultation->isAttendant($returnUser)
                ? $consultation->client?->name
                : ($consultation->attendant?->attendantProfile?->display_name ?? $consultation->attendant?->name)
        );
    $returnIsAttendant = $consultation !== null && $consultation->isAttendant($returnUser);
    $returnOnWorkspace = request()->routeIs('consultations.workspace');
@endphp

@if ($consultation !== null && ! $returnOnWorkspace)
    <div
        class="consultation-return"
        data-consultation-return
        role="status"
        aria-live="polite"
    >
        <span class="consultation-return__pulse" aria-hidden="true"></span>

        <div class="consultation-return__body">
            <p class="consultation-return__title">
                Atendimento #{{ $consultation->id }} em andamento
            </p>
            <p class="consultation-return__text">
                {{ $returnIsAttendant ? 'Você está atendendo' : 'Você está em atendimento com' }}
                {{ $returnCounterparty }}.
                Os créditos continuam sendo descontados até o encerramento.
            </p>
        </div>

        <a
            class="btn btn-primary btn-sm consultation-return__action"
            href="{{ route('consultations.workspace', $consultation) }}"
            data-consultation-return-link
        >
            Voltar ao atendimento
        </a>
    </div>
@endif
