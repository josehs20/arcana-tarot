<x-ui.card class="mb-4">
    <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">Pacotes de créditos do checkout</h2>
        <span class="badge text-bg-secondary">{{ count($creditPackages) }} pacote(s)</span>
    </x-ui.card-header>
    <x-ui.card-body>
        <p class="form-text mb-3">
            A administração define a <strong>quantidade de créditos</strong> de cada pacote (o nome é opcional).
            O valor em reais continua sendo calculado no servidor como
            <strong>créditos × valor do crédito</strong> — o cliente nunca envia preço pelo navegador.
            Pacotes fora dos limites de recarga ({{ $packageAmountLimits['min_label'] }} a
            {{ $packageAmountLimits['max_label'] }}) não aparecem no checkout.
        </p>

        @php
            $unavailablePackages = collect($creditPackages)
                ->reject(fn (array $package): bool => $package['available']);
        @endphp

        @if ($creditUnitPriceCents !== null && $unavailablePackages->isNotEmpty())
            <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                {{ $unavailablePackages->count() }} pacote(s) do catálogo estão fora dos limites de recarga e não
                aparecem no checkout: {{ $unavailablePackages->pluck('label')->join(', ') }}.
            </x-ui.alert>
        @endif

        @if ($creditUnitPriceCents === null)
            <x-ui.alert type="warning" :dismissible="false" class="mb-3">
                Defina o valor do crédito em
                <a href="{{ route('admin.finance.index') }}">Valor do crédito e saldo mínimo</a>
                para que os pacotes voltem a aparecer no checkout.
            </x-ui.alert>
        @endif

        @php
            $savedRows = collect($creditPackages)
                ->map(fn (array $package): array => [
                    'credits' => $package['credits'],
                    'label' => $package['custom_label'] ? $package['label'] : '',
                ])
                ->all();

            $oldRows = old('packages');
            $rows = is_array($oldRows) ? array_values($oldRows) : $savedRows;

            if ($rows === []) {
                $rows[] = ['credits' => '', 'label' => ''];
            }
        @endphp

        <form
            method="POST"
            action="{{ route('admin.finance.packages.update') }}"
            data-credit-packages-form
            data-credit-unit-price-cents="{{ $creditUnitPriceCents ?? '' }}"
            data-min-amount-cents="{{ $packageAmountLimits['min_cents'] }}"
            data-max-amount-cents="{{ $packageAmountLimits['max_cents'] }}"
            data-next-package-index="{{ count($rows) }}"
        >
            @csrf
            @method('PATCH')

            <div class="credit-package-rows" data-package-rows>
                @foreach ($rows as $index => $row)
                    @php
                        $creditsValue = is_array($row) ? ($row['credits'] ?? '') : '';
                        $labelValue = is_array($row) ? ($row['label'] ?? '') : '';
                        $creditsError = $errors->first('packages.'.$index.'.credits');
                        $labelError = $errors->first('packages.'.$index.'.label');
                    @endphp

                    <div class="credit-package-row" data-package-row>
                        <div class="row g-2 align-items-start">
                            <div class="col-md-6">
                                <label class="form-label" for="package-label-{{ $index }}">Nome do pacote (opcional)</label>
                                <input
                                    class="form-control @if ($labelError) is-invalid @endif"
                                    type="text"
                                    id="package-label-{{ $index }}"
                                    name="packages[{{ $index }}][label]"
                                    value="{{ $labelValue }}"
                                    maxlength="{{ $maxPackageLabelLength }}"
                                    placeholder="Ex.: Pacote inicial"
                                >
                                @if ($labelError)
                                    <div class="invalid-feedback">{{ $labelError }}</div>
                                @endif
                            </div>

                            <div class="col-md-3">
                                <label class="form-label required" for="package-credits-{{ $index }}">Créditos</label>
                                <input
                                    class="form-control @if ($creditsError) is-invalid @endif"
                                    type="number"
                                    id="package-credits-{{ $index }}"
                                    name="packages[{{ $index }}][credits]"
                                    value="{{ $creditsValue }}"
                                    min="1"
                                    max="{{ $maxPackageCredits }}"
                                    step="1"
                                    data-package-credits
                                    required
                                >
                                @if ($creditsError)
                                    <div class="invalid-feedback">{{ $creditsError }}</div>
                                @endif
                            </div>

                            <div class="col-md-2">
                                <span class="credit-package-row__total" data-package-total>—</span>
                                <span class="form-text d-block">Total no checkout</span>
                            </div>

                            <div class="col-md-1 d-flex justify-content-end">
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm"
                                    data-remove-package
                                    aria-label="Remover pacote"
                                >×</button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="button" class="btn btn-ghost btn-sm" data-add-package>Adicionar pacote</button>
                <x-ui.button type="submit" variant="primary" size="sm">Salvar pacotes</x-ui.button>
            </div>

            {{--
                O template precisa ficar DENTRO do <form>: o editor em
                resources/js/credit-packages.js busca `[data-package-template]`
                a partir do próprio formulário.
            --}}
            <template data-package-template>
                <div class="credit-package-row" data-package-row>
                    <div class="row g-2 align-items-start">
                        <div class="col-md-6">
                            <label class="form-label" for="package-label-__INDEX__">Nome do pacote (opcional)</label>
                            <input
                                class="form-control"
                                type="text"
                                id="package-label-__INDEX__"
                                name="packages[__INDEX__][label]"
                                value=""
                                maxlength="{{ $maxPackageLabelLength }}"
                                placeholder="Ex.: Pacote inicial"
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required" for="package-credits-__INDEX__">Créditos</label>
                            <input
                                class="form-control"
                                type="number"
                                id="package-credits-__INDEX__"
                                name="packages[__INDEX__][credits]"
                                value=""
                                min="1"
                                max="{{ $maxPackageCredits }}"
                                step="1"
                                data-package-credits
                                required
                            >
                        </div>

                        <div class="col-md-2">
                            <span class="credit-package-row__total" data-package-total>—</span>
                            <span class="form-text d-block">Total no checkout</span>
                        </div>

                        <div class="col-md-1 d-flex justify-content-end">
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                data-remove-package
                                aria-label="Remover pacote"
                            >×</button>
                        </div>
                    </div>
                </div>
            </template>
        </form>
    </x-ui.card-body>
</x-ui.card>
