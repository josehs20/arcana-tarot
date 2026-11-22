@php($editing = $promotion->exists)

<x-layouts.panel
    variant="admin"
    :title="($editing ? 'Editar promoção' : 'Nova promoção')"
>
    <x-layouts.page-header
        :title="$editing ? 'Editar promoção' : 'Nova promoção'"
        :subtitle="$editing
            ? 'Alterações valem para novas compras. Pagamentos já confirmados mantêm o bônus concedido.'
            : 'Defina quantos créditos o cliente compra e quantos extras ele recebe. O bônus só é creditado após a confirmação do pagamento.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.promotions.index') }}">Voltar às promoções</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $editing ? route('admin.promotions.update', $promotion) : route('admin.promotions.store') }}"
                    >
                        @csrf
                        @if ($editing)
                            @method('PATCH')
                        @endif

                        <x-ui.field
                            name="title"
                            label="Título da promoção"
                            :value="$promotion->title"
                            placeholder="Ex.: Semana do tarô"
                            maxlength="120"
                            required
                        />

                        <x-ui.textarea
                            name="description"
                            label="Descrição (opcional)"
                            :value="$promotion->description"
                            rows="3"
                            maxlength="255"
                            help="Texto curto exibido junto da oferta na home e na carteira."
                        />

                        <div class="row g-3">
                            <div class="col-md-6">
                                <x-ui.field
                                    name="buy_credits"
                                    type="number"
                                    label="Créditos comprados"
                                    :value="$promotion->buy_credits"
                                    min="1"
                                    max="10000"
                                    inputmode="numeric"
                                    required
                                />
                            </div>

                            <div class="col-md-6">
                                <x-ui.field
                                    name="bonus_credits"
                                    type="number"
                                    label="Créditos de bônus"
                                    :value="$promotion->bonus_credits"
                                    min="1"
                                    max="10000"
                                    inputmode="numeric"
                                    required
                                />
                            </div>
                        </div>

                        <x-ui.select
                            name="is_active"
                            label="Estado"
                            :value="$promotion->is_active ? '1' : '0'"
                            :options="[
                                '1' => 'Ativa — aparece na home e na carteira',
                                '0' => 'Inativa — oculta e sem conceder bônus',
                            ]"
                            required
                        />

                        <div class="row g-3">
                            <div class="col-md-6">
                                <x-ui.field
                                    name="starts_at"
                                    type="datetime-local"
                                    label="Início (opcional)"
                                    :value="$promotion->starts_at?->format('Y-m-d\TH:i')"
                                    help="Em branco: passa a valer assim que estiver ativa."
                                />
                            </div>

                            <div class="col-md-6">
                                <x-ui.field
                                    name="ends_at"
                                    type="datetime-local"
                                    label="Término (opcional)"
                                    :value="$promotion->ends_at?->format('Y-m-d\TH:i')"
                                    help="Em branco: vale enquanto estiver ativa."
                                />
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <x-ui.button type="submit" variant="primary">
                                {{ $editing ? 'Salvar promoção' : 'Criar promoção' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.promotions.index') }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card class="h-100">
                <x-ui.card-header>Como funciona</x-ui.card-header>
                <x-ui.card-body>
                    <ul class="panel-list list-unstyled mb-0">
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">O bônus é calculado no servidor a partir da quantidade de créditos da compra — nunca de um campo do formulário de pagamento.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">O lançamento ocorre dentro da transação de confirmação do pagamento e é idempotente: webhooks repetidos não creditam duas vezes.</span>
                        </li>
                        <li class="panel-list__item">
                            <span class="panel-list__title fw-normal">Inativar ou remover não altera o histórico: cada pagamento guarda uma cópia do bônus concedido.</span>
                        </li>
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
