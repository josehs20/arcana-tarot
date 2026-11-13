<x-layouts.panel variant="panel" title="Carteira e créditos">
    <x-layouts.page-header
        title="Carteira e créditos"
        subtitle="Consulte seu saldo, o histórico de movimentações e as opções de recarga."
    >
        <x-slot name="actions">
            @if ($returnUrl)
                <a class="btn btn-primary btn-sm" href="{{ $returnUrl }}" data-credit-return>Voltar ao atendimento</a>
            @endif
            <a class="btn btn-ghost btn-sm" href="{{ route('client.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Saldo atual</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <p class="stat-card__label mb-1">Créditos disponíveis</p>
                    <p class="display-5 mb-3">{{ \App\Support\Money::format($wallet->balance_cents) }}</p>

                    <dl class="row small mb-0">
                        <dt class="col-sm-5 text-ivory-muted fw-normal">Valor por minuto</dt>
                        <dd class="col-sm-7">
                            {{ $startingPriceLabel ? 'A partir de '.$startingPriceLabel : 'Definido pelo atendente escolhido' }}
                        </dd>

                        <dt class="col-sm-5 text-ivory-muted fw-normal">Forma de cobrança</dt>
                        <dd class="col-sm-7">Descontada do saldo apenas durante a consulta</dd>
                    </dl>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-5">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Recarga</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    @if ($checkoutEnabled && $packages !== [])
                        <p class="mb-2">
                            Escolha um pacote de créditos. Cada crédito custa
                            {{ \App\Support\Money::format($creditUnitPriceCents) }} e o pagamento é feito no
                            checkout seguro da InfinitePay.
                        </p>

                        <div class="d-grid gap-2">
                            @foreach ($packages as $package)
                                <form method="POST" action="{{ route('client.credits.checkout.store') }}">
                                    @csrf
                                    <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <input type="hidden" name="package" value="{{ $package['key'] }}">
                                    <x-ui.button type="submit" variant="primary" :block="true">
                                        {{ $package['label'] }} —
                                        {{ \App\Support\Money::format($package['amount_cents']) }}
                                    </x-ui.button>
                                </form>
                            @endforeach
                        </div>

                        @error('package')
                            <div class="invalid-feedback d-block mt-2">{{ $message }}</div>
                        @enderror

                        <p class="form-text mt-3 mb-0">
                            Os créditos são liberados somente depois que a InfinitePay confirma o pagamento no servidor.
                        </p>
                    @elseif ($sandboxEnabled && $creditUnitPriceCents !== null)
                        @php
                            $minReais = intdiv($minAmountCents, 100).'.'.str_pad((string) ($minAmountCents % 100), 2, '0', STR_PAD_LEFT);
                            $maxReais = intdiv($maxAmountCents, 100).'.'.str_pad((string) ($maxAmountCents % 100), 2, '0', STR_PAD_LEFT);
                        @endphp

                        <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                            <strong>Ambiente de teste.</strong> Nenhum valor real será cobrado. A cobrança é gerada por um provedor mock
                            e só libera saldo após um webhook autenticado.
                        </x-ui.alert>

                        <p class="mb-2">Escolha o valor da recarga. Cada crédito custa {{ \App\Support\Money::format($creditUnitPriceCents) }}.</p>

                        <form method="POST" action="{{ route('client.credits.store') }}">
                            @csrf
                            <input type="hidden" name="operation_id" value="{{ $operationId }}">

                            <div class="mb-3">
                                <label class="form-label required" for="amount_reais">Valor da recarga (R$)</label>
                                <input
                                    class="form-control @error('amount_cents') is-invalid @enderror"
                                    type="number"
                                    id="amount_reais"
                                    name="amount_reais"
                                    value="{{ old('amount_reais') }}"
                                    min="{{ $minReais }}"
                                    max="{{ $maxReais }}"
                                    step="0.01"
                                    inputmode="decimal"
                                    required
                                >
                                <div class="form-text">
                                    Entre {{ \App\Support\Money::format($minAmountCents) }} e
                                    {{ \App\Support\Money::format($maxAmountCents) }}. O valor deve ser múltiplo de
                                    {{ \App\Support\Money::format($creditUnitPriceCents) }}.
                                </div>
                                @error('amount_cents')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <x-ui.button type="submit" variant="primary" :block="true">
                                Gerar cobrança PIX de teste
                            </x-ui.button>
                        </form>
                    @else
                        <x-ui.alert type="warning" :dismissible="false" class="mb-0">
                            A recarga está indisponível porque o valor do crédito, o checkout ou o modo sandbox ainda não
                            foram configurados. Nenhum saldo é creditado manualmente.
                        </x-ui.alert>
                    @endif
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card class="mt-4">
        <x-ui.card-header>
            <h2 class="h6 mb-0">Pedidos de recarga</h2>
        </x-ui.card-header>

        @if ($payments->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◈"
                    title="Nenhum pedido de recarga"
                    text="As cobranças PIX de teste e seus status aparecerão aqui. O saldo só muda após a confirmação server-side."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Pedidos de recarga PIX do cliente</caption>
                    <thead>
                        <tr>
                            <th scope="col">Criado em</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Créditos</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="visually-hidden">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td class="text-nowrap">{{ $payment->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($payment->amount_cents) }}</td>
                                <td>{{ $payment->credits }}</td>
                                <td>
                                    <x-ui.status-badge :status="$payment->status->value" :label="$payment->status->label()" />
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('client.credits.payments.show', $payment) }}">
                                        Detalhes
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $payments->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>

    <x-ui.card class="mt-4">
        <x-ui.card-header class="d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Histórico de movimentações</h2>
            <span class="form-text mt-0">Saldo calculado a partir de lançamentos imutáveis</span>
        </x-ui.card-header>

        @if ($entries->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhuma movimentação"
                    text="Créditos e débitos aparecerão aqui assim que forem registrados. O saldo nunca é alterado sem um lançamento correspondente."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Histórico de créditos e débitos da sua carteira</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Saldo após</th>
                            <th scope="col">Origem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $entry->type === \App\Enums\LedgerEntryType::Credit ? 'text-bg-success' : 'text-bg-danger' }}">
                                        {{ $entry->type->label() }}
                                    </span>
                                </td>
                                <td class="text-nowrap fw-medium">
                                    {{ $entry->type === \App\Enums\LedgerEntryType::Credit ? '+' : '−' }}
                                    {{ \App\Support\Money::format($entry->amount_cents) }}
                                </td>
                                <td class="text-nowrap">{{ \App\Support\Money::format($entry->balance_after_cents) }}</td>
                                <td>{{ $entry->sourceLabel() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $entries->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>

    <x-ui.alert type="info" :dismissible="false" class="mt-4">
        Precisa de ajuda com uma recarga? Entre em contato com o suporte informando seu e-mail de cadastro.
    </x-ui.alert>
</x-layouts.panel>
