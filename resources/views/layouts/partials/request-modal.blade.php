{{--
    Modal de resposta a uma solicitação de atendimento.

    Fica no layout do painel (`layouts/partials/realtime-alerts`) para acompanhar
    o atendente em qualquer tela — painel, histórico, perfil, consulta aberta —
    e não apenas no dashboard. `resources/js/user-channel.js` abre este modal
    quando o canal privado entrega `consultation.request` e também quando a
    ressincronização encontra pedidos que já estavam aguardando, sempre com a
    sirene tocando.

    Aceitar e recusar passam pelas rotas do servidor, que revalidam estado,
    participação e disponibilidade — o modal nunca decide nada sozinho. Sem
    JavaScript, a fila em `dashboard/attendant.blade.php` continua oferecendo os
    formulários POST/PATCH de aceite e recusa.
--}}
<div
    class="modal fade"
    tabindex="-1"
    aria-labelledby="request-modal-title"
    data-request-modal
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="request-modal-title">Nova solicitação de atendimento</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="panel-list__title mb-1" data-request-client>Cliente</p>
                <p class="panel-list__meta" data-request-meta>Aguardando detalhes da solicitação</p>

                <div
                    class="alert alert-danger py-2 px-3 mt-3 mb-0 d-none"
                    role="alert"
                    aria-live="assertive"
                    data-request-error
                ></div>

                {{--
                    Navegadores só liberam áudio depois de uma interação. Enquanto
                    o contexto estiver suspenso, este aviso explica por que a
                    sirene ainda não tocou e o que fazer — some no primeiro gesto.
                --}}
                <p class="form-text mt-3 mb-0 d-none" data-request-audio-hint>
                    Clique em qualquer ponto desta janela para liberar o som do alerta neste navegador.
                </p>

                <p class="form-text mt-3 mb-0">
                    O aceite inicia a consulta imediatamente e mantém sua disponibilidade como
                    <strong>Ocupado</strong> até o encerramento. A recusa encerra a solicitação sem cobrança.
                    A sirene para assim que você responde ou fecha esta janela.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-request-reject>Recusar</button>
                <button type="button" class="btn btn-primary" data-request-accept>Aceitar atendimento</button>
            </div>
        </div>
    </div>
</div>
