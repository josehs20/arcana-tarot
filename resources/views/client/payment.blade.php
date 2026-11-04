<x-layouts.panel variant="panel" :title="'Recarga #' . $payment->getKey() . ' | Arcana Tarot'">
    <x-layouts.page-header
        :title="'Recarga #' . $payment->getKey()"
        subtitle="Acompanhe o status do pedido e as instruções de pagamento."
    >
        <x-slot name="actions">
            @if ($returnUrl)
                <a class="btn btn-primary btn-sm" href="{{ $returnUrl }}">Voltar ao atendimento</a>
            @endif
            <a class="btn btn-ghost btn-sm" href="{{ route('client.credits.index') }}">Voltar à carteira</a>
        </x-slot>
    </x-layouts.page-header>

    @if ($payment->status === \App\Enums\PaymentStatus::Confirmed)
        <x-ui.alert type="success" :dismissible="false" class="mb-4">
            Pagamento confirmado pelo provedor. O valor foi lançado no livro-razão imutável e já está disponível na carteira.
        </x-ui.alert>
    @elseif ($payment->status === \App\Enums\PaymentStatus::Pending)
        <x-ui.alert type="warning" :dismissible="false" class="mb-4">
            <strong>Aguardando confirmação do provedor.</strong> Esta página não marca o pedido como pago. O status só muda
            depois de um webhook autenticado e validado no servidor.
        </x-ui.alert>
    @else
        <x-ui.alert type="danger" :dismissible="false" class="mb-4">
            <strong>Pagamento não confirmado.</strong>
            @switch($payment->failure_reason)
                @case('provider_declined')
                    O provedor recusou a cobrança. Nenhum crédito foi liberado.
                    @break
                @case('provider_expired')
                    A cobrança expirou sem confirmação. Nenhum crédito foi liberado.
                    @break
                @case('provider_error')
                    O provedor não conseguiu gerar a cobrança. Nenhum crédito foi liberado.
                    @break
                @default
                    O pedido não foi confirmado. Nenhum crédito foi liberado.
            @endswitch
        </x-ui.alert>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Resumo do pedido</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <dl class="row small mb-0">
                        <dt class="col-sm-5 text-ivory-muted fw-normal">Status</dt>
                        <dd class="col-sm-7">
                            <x-ui.status-badge :status="$payment->status->value" :label="$payment->status->label()" />
                        </dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Valor</dt>
                        <dd class="col-sm-7">{{ \App\Support\Money::format($payment->amount_cents) }}</dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Créditos</dt>
                        <dd class="col-sm-7">{{ $payment->credits }}</dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Preço do crédito</dt>
                        <dd class="col-sm-7">{{ \App\Support\Money::format($payment->credit_unit_price_cents) }}</dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Criado em</dt>
                        <dd class="col-sm-7">{{ $payment->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Expira em</dt>
                        <dd class="col-sm-7">{{ $payment->expires_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        @if ($payment->paid_at !== null)
                            <dt class="col-sm-5 text-ivory-muted fw-normal">Confirmado em</dt>
                            <dd class="col-sm-7">{{ $payment->paid_at->format('d/m/Y H:i') }}</dd>
                        @endif

                        @if ($payment->verified_at !== null)
                            <dt class="col-sm-5 text-ivory-muted fw-normal">Verificado no provedor em</dt>
                            <dd class="col-sm-7">{{ $payment->verified_at->format('d/m/Y H:i') }}</dd>
                        @endif

                        @if ($payment->transaction_nsu !== null)
                            <dt class="col-sm-5 text-ivory-muted fw-normal">Transação no provedor</dt>
                            <dd class="col-sm-7 text-break"><code>{{ $payment->transaction_nsu }}</code></dd>
                        @endif

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Referência externa</dt>
                        <dd class="col-sm-7 text-break"><code>{{ $payment->external_reference }}</code></dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Provedor</dt>
                        <dd class="col-sm-7">{{ $payment->provider }}</dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-5">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Instruções do provedor</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    @if ($payment->status === \App\Enums\PaymentStatus::Pending)
                        @if ($payment->provider === \App\Support\Payments\InfinitePay\InfinitePayGateway::NAME)
                            <p class="mb-2">
                                O pagamento é processado no checkout seguro da InfinitePay (PIX ou cartão). O saldo é
                                liberado automaticamente assim que o provedor confirmar a cobrança.
                            </p>

                            @if ($payment->checkout_url !== null && $payment->checkout_url !== '')
                                <a
                                    class="btn btn-primary w-100 mb-3"
                                    href="{{ $payment->checkout_url }}"
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                    Abrir checkout da InfinitePay
                                </a>
                            @else
                                <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                                    O link de pagamento ainda não foi gerado para este pedido. Volte à carteira e escolha o
                                    pacote novamente.
                                </x-ui.alert>
                            @endif

                            <p class="form-text mb-0">
                                Se você já pagou, aguarde alguns instantes e atualize esta página. A confirmação é feita no
                                servidor, nunca pelo navegador.
                            </p>
                        @else
                            <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                                <strong>Sandbox: não pague este código.</strong> Ele não é um PIX válido e não movimenta dinheiro real.
                            </x-ui.alert>

                            @if ($payment->pix_copy_paste !== null)
                                <label class="form-label" for="pix_copy_paste">Código de teste retornado pelo mock</label>
                                <textarea class="form-control font-monospace mb-3" id="pix_copy_paste" rows="3" readonly>{{ $payment->pix_copy_paste }}</textarea>
                            @else
                                <p class="text-ivory-muted">O provedor ainda não retornou instruções para este pedido.</p>
                            @endif

                            @if ($sandboxEnabled)
                                <p class="small mb-2">Para simular a notificação assinada do provedor, execute no servidor:</p>
                                <pre class="small text-break mb-3"><code>php artisan pix:sandbox-simulate {{ $payment->getKey() }} approved</code></pre>
                                <p class="form-text mb-0">
                                    A simulação envia um webhook assinado para esta aplicação. O crédito só é liberado se a assinatura,
                                    o timestamp, o valor e o identificador do evento forem válidos.
                                </p>
                            @else
                                <p class="form-text mb-0">O modo sandbox está desabilitado neste ambiente.</p>
                            @endif
                        @endif
                    @elseif ($payment->status === \App\Enums\PaymentStatus::Confirmed)
                        <p class="mb-3">O crédito correspondente foi registrado como entrada no seu extrato.</p>
                        <a class="btn btn-primary w-100" href="{{ route('client.credits.index') }}">Ver saldo e extrato</a>
                    @else
                        <p class="mb-3">Este pedido está encerrado e não pode ser confirmado novamente.</p>
                        <a class="btn btn-primary w-100" href="{{ route('client.credits.index') }}">Criar nova recarga</a>
                    @endif
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.alert type="info" :dismissible="false" class="mt-4">
        A confirmação nunca depende de redirecionamento do navegador. Webhooks repetidos são registrados uma única vez e não
        duplicam créditos.
    </x-ui.alert>
</x-layouts.panel>

