@php
    $editing = $user !== null;
    $isAttendant = $user?->isAttendant() ?? false;
    $photoUrl = $profile?->photoUrl();
    $prices = $profile?->pricesByModality() ?? [];
    $specialtiesValue = collect(old('specialties', $profile?->specialties ?? []))->implode(', ');
    $commissionValue = old(
        'commission_rate',
        $profile !== null ? \App\Support\Rate::inputValue($profile->commission_rate_bps) : '30',
    );
    $phoneValue = old('phone', $user?->phone);
    $birthDateValue = old('birth_date', $user?->birth_date?->toDateString());
    $genderValue = old('gender', $user?->gender?->value);
@endphp

<x-layouts.panel
    variant="admin"
    :title="($editing ? 'Editar usuário' : 'Novo atendente')"
>
    <x-layouts.page-header
        :title="$editing ? ($isAttendant ? 'Editar atendente' : 'Editar cliente') : 'Novo atendente'"
        :subtitle="$editing
            ? 'Atualize os dados da conta'.($isAttendant ? ', o perfil do diretório, a comissão e os preços por minuto.' : ' e a senha de acesso.')
            : 'Cadastre o profissional, defina a senha provisória e o preço por minuto de cada modalidade. O perfil entra offline até o atendente entrar no ar.'"
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.users.index') }}">Voltar aos usuários</a>
            @if ($editing)
                <a class="btn btn-ghost btn-sm" href="{{ route('admin.users.create') }}">Novo atendente</a>
                @if ($isAttendant)
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.commissions.attendant', $user) }}">Extrato de comissão</a>
                @endif
            @endif
        </x-slot>
    </x-layouts.page-header>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-4" :dismissible="false">
            Revise os campos destacados para salvar os dados.
        </x-ui.alert>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <x-ui.card>
                <x-ui.card-header>Dados da conta</x-ui.card-header>
                <x-ui.card-body>
                    <form
                        method="POST"
                        action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}"
                        enctype="multipart/form-data"
                    >
                        @csrf
                        @if ($editing)
                            @method('PATCH')
                        @endif


                        <div class="row">
                            <div class="col-md-6">
                                <x-ui.field
                                    name="name"
                                    label="Nome completo"
                                    :value="$user?->name"
                                    placeholder="Ex.: Marina Duarte"
                                    maxlength="255"
                                    autocomplete="off"
                                    required
                                />
                            </div>
                            <div class="col-md-6">
                                <x-ui.field
                                    name="email"
                                    label="E-mail de acesso"
                                    type="email"
                                    :value="$user?->email"
                                    placeholder="atendente@exemplo.com"
                                    maxlength="255"
                                    autocomplete="off"
                                    required
                                />
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <x-ui.field
                                    name="phone"
                                    label="Telefone com DDD"
                                    type="tel"
                                    :value="$phoneValue"
                                    placeholder="(11) 99999-0000"
                                    maxlength="20"
                                    inputmode="tel"
                                    help="Opcional. O número é salvo sem máscara."
                                />
                            </div>
                            <div class="col-md-6">
                                <x-ui.field
                                    name="birth_date"
                                    label="Data de nascimento"
                                    type="date"
                                    :value="$birthDateValue"
                                    help="Opcional. A conta exige 18 anos ou mais."
                                />
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <x-ui.select
                                    name="gender"
                                    label="Sexo"
                                    :options="$genders"
                                    :value="$genderValue"
                                    placeholder="Não informado"
                                    help="Opcional. O cliente escolhe no cadastro; contas antigas e do Google podem ficar vazias."
                                />
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <x-ui.field
                                    name="password"
                                    label="{{ $editing ? 'Nova senha (opcional)' : 'Senha provisória' }}"
                                    type="password"
                                    :required="! $editing"
                                    autocomplete="new-password"
                                    :help="$editing
                                        ? 'Em branco mantém a senha atual. A troca é registrada na auditoria.'
                                        : 'Mínimo de 8 caracteres. Combine com o atendente por um canal seguro.'"
                                />
                            </div>
                            <div class="col-md-6">
                                <x-ui.field
                                    name="password_confirmation"
                                    label="Confirmação da senha"
                                    type="password"
                                    :required="! $editing"
                                    autocomplete="new-password"
                                />
                            </div>
                        </div>

                        @if ($editing && ! $isAttendant)
                            <p class="form-text mb-0">
                                Este é um cliente: para os dados do diretório, a comissão e os preços por
                                minuto, edite o perfil de um atendente.
                            </p>
                        @endif

                        @if (! $editing || $isAttendant)
                            <hr class="my-4">

                            <h2 class="h6 mb-3">Perfil no diretório</h2>

                            <x-ui.field
                                name="display_name"
                                label="Nome profissional"
                                :value="$profile?->display_name"
                                placeholder="Ex.: Marina do Tarot"
                                maxlength="60"
                                help="É o nome exibido para os clientes. Máximo de 60 caracteres."
                                required
                            />

                            <x-ui.textarea
                                name="bio"
                                label="Apresentação (opcional)"
                                :value="$profile?->bio"
                                rows="4"
                                maxlength="1000"
                                help="Texto curto sobre a abordagem do atendente. Até 1000 caracteres."
                            />

                            <x-ui.field
                                name="specialties"
                                label="Especialidades (opcional)"
                                :value="$specialtiesValue"
                                placeholder="Amor, trabalho, espiritualidade"
                                maxlength="240"
                                help="Separe por vírgulas. Até 6 itens."
                            />


                            <div class="mb-3">
                                <label class="form-label" for="photo">Foto do perfil</label>
                                <x-ui.image-input
                                    name="photo"
                                    :profile="\App\Support\Images\ImageProfile::AttendantPhoto"
                                >
                                    @if ($photoUrl)
                                        Enviar um arquivo substitui a foto atual.
                                    @else
                                        Sem foto, o diretório exibe as iniciais do nome profissional.
                                    @endif
                                </x-ui.image-input>
                            </div>

                            @if ($photoUrl)
                                <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                                    <img
                                        src="{{ $photoUrl }}"
                                        alt="Foto atual de {{ $profile->display_name }}"
                                        width="72"
                                        height="72"
                                        style="object-fit: cover; border-radius: 50%;"
                                    >
                                    <div class="form-check mb-0">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="remove_photo"
                                            name="remove_photo"
                                            value="1"
                                            @checked(old('remove_photo'))
                                        >
                                        <label class="form-check-label" for="remove_photo">
                                            Remover a foto atual
                                        </label>
                                    </div>
                                </div>
                            @endif

                            <div class="mb-4">
                                <label class="form-label required" for="is_published">Diretório</label>
                                <select
                                    class="form-select @error('is_published') is-invalid @enderror"
                                    id="is_published"
                                    name="is_published"
                                    required
                                >
                                    <option value="1" @selected((string) old('is_published', $profile?->is_published ? '1' : '0') === '1')>
                                        Publicado — visível no diretório público
                                    </option>
                                    <option value="0" @selected((string) old('is_published', $profile?->is_published ? '1' : '0') === '0')>
                                        Oculto — fora do diretório e sem novas solicitações
                                    </option>
                                </select>
                                <div class="form-text">
                                    Despublicar coloca o atendente offline imediatamente. Consultas em
                                    andamento não são afetadas.
                                </div>
                                @error('is_published')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        @endif


                        @if (! $editing || $isAttendant)
                            <hr class="my-4">

                            <h2 class="h6 mb-3">Comissão e preços</h2>

                            <x-ui.field
                                name="commission_rate"
                                label="Comissão da plataforma (%)"
                                type="text"
                                :value="$commissionValue"
                                placeholder="30"
                                maxlength="6"
                                inputmode="decimal"
                                help="Percentual retido pela plataforma em cada consulta. Aceita ponto ou vírgula (ex.: 25,5)."
                                required
                            />

                            @error('prices')
                                <x-ui.alert type="danger" class="mb-3" :dismissible="false">{{ $message }}</x-ui.alert>
                            @enderror

                            <div class="row g-3">
                                @foreach ($modalities as $modality)
                                    @php
                                        $enabled = $catalog->isEnabled($modality);
                                        $priceKey = 'prices.'.$modality->value;
                                        $fieldId = 'price_'.$modality->value;
                                        $currentPrice = $prices[$modality->value] ?? null;
                                    @endphp

                                    <div class="col-md-6">
                                        <div class="border rounded p-3 h-100">
                                            <span class="d-flex align-items-center gap-2 fw-medium mb-2">
                                                <x-ui.icon :name="$modality->icon()" width="16" height="16" />
                                                {{ $modality->label() }}
                                                @if ($modality === \App\Enums\ConsultationModality::Text)
                                                    <span class="badge text-bg-secondary">Base</span>
                                                @endif
                                            </span>

                                            @if ($enabled)
                                                <label class="form-label small mb-1" for="{{ $fieldId }}">
                                                    Preço por minuto (centavos)
                                                </label>
                                                <input
                                                    class="form-control form-control-sm @error($priceKey) is-invalid @enderror"
                                                    type="number"
                                                    id="{{ $fieldId }}"
                                                    name="prices[{{ $modality->value }}]"
                                                    min="1"
                                                    max="1000000"
                                                    step="1"
                                                    value="{{ old($priceKey, $currentPrice) }}"
                                                    placeholder="Ex.: 250"
                                                >
                                                <div class="form-text">
                                                    @if ($currentPrice !== null)
                                                        Atual: {{ $profile?->pricePerMinuteLabel($modality) }} por minuto.
                                                    @elseif ($modality === \App\Enums\ConsultationModality::Text)
                                                        Obrigatório: sem preço de texto o atendente não recebe solicitações.
                                                    @else
                                                        Em branco mantém a modalidade desabilitada.
                                                    @endif
                                                </div>
                                                @error($priceKey)
                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                @enderror
                                            @else
                                                <p class="form-text mb-0">Indisponível na infraestrutura.</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="form-text mt-3">
                                Limpar o preço de uma modalidade a remove do atendente. Modalidades com
                                infraestrutura desligada não são alteradas por esta tela.
                            </div>
                        @endif

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <x-ui.button type="submit" variant="primary">
                                {{ $editing ? 'Salvar dados' : 'Cadastrar atendente' }}
                            </x-ui.button>
                            <a class="btn btn-ghost" href="{{ route('admin.users.index') }}">Cancelar</a>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>


        <div class="col-lg-4">
            @if ($editing)
                <x-ui.card class="mb-4">
                    <x-ui.card-header>Situação da conta</x-ui.card-header>
                    <x-ui.card-body>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-medium">
                                {{ match ($user->role->value) {
                                    'admin' => 'Administrador',
                                    'attendant' => 'Atendente',
                                    'client' => 'Cliente',
                                    default => $user->role->value,
                                } }}
                            </span>
                            @if ($user->is_active)
                                <x-ui.status-badge status="online" label="Ativa" />
                            @else
                                <x-ui.status-badge status="inactive" />
                            @endif
                        </div>

                        <p class="form-text">
                            Desde {{ $user->created_at?->format('d/m/Y') }}.
                            @if ($isAttendant && $profile)
                                Presença atual:
                                <x-ui.status-badge :status="$profile->publicStatus()" />
                            @endif
                        </p>

                        @can('manageStatus', $user)
                            <form method="POST" action="{{ route('admin.users.status', $user) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                                <x-ui.button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    :class="$user->is_active ? 'text-danger' : ''"
                                >
                                    {{ $user->is_active ? 'Desativar conta' : 'Ativar conta' }}
                                </x-ui.button>
                            </form>
                            <div class="form-text mt-2">
                                Desativar bloqueia o acesso e coloca o atendente offline.
                            </div>
                        @else
                            <p class="form-text mb-0">Contas administrativas são protegidas nesta tela.</p>
                        @endcan
                    </x-ui.card-body>
                </x-ui.card>

                @if ($user->isClient())
                    <x-ui.card class="mb-4">
                        <x-ui.card-header>Créditos do cliente</x-ui.card-header>
                        <x-ui.card-body>
                            <p class="mb-3">
                                Saldo atual:
                                <span class="fw-semibold">
                                    {{ \App\Support\Money::format($wallet?->balance_cents ?? 0) }}
                                </span>
                            </p>

                            <form method="POST" action="{{ route('admin.users.credit.store', $user) }}">
                                @csrf
                                <input type="hidden" name="operation_id" value="{{ $operationId }}">

                                <div class="mb-3">
                                    <label class="form-label required" for="credit_type">Operação</label>
                                    <select
                                        class="form-select form-select-sm @error('type') is-invalid @enderror"
                                        id="credit_type"
                                        name="type"
                                        required
                                    >
                                        @foreach ($ledgerTypes as $type)
                                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>
                                                {{ $type->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('type')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required" for="amount_cents">Valor em centavos</label>
                                    <input
                                        class="form-control form-control-sm @error('amount_cents') is-invalid @enderror"
                                        type="number"
                                        id="amount_cents"
                                        name="amount_cents"
                                        min="1"
                                        step="1"
                                        value="{{ old('amount_cents') }}"
                                        placeholder="Ex.: 2000"
                                        required
                                    >
                                    <div class="form-text">R$ 20,00 equivale a 2000 centavos.</div>
                                    @error('amount_cents')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required" for="reason">Motivo</label>
                                    <textarea
                                        class="form-control form-control-sm @error('reason') is-invalid @enderror"
                                        id="reason"
                                        name="reason"
                                        rows="2"
                                        maxlength="500"
                                        required
                                    >{{ old('reason') }}</textarea>
                                    @error('reason')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <x-ui.button type="submit" variant="ghost" size="sm">Registrar ajuste</x-ui.button>
                                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.wallets.show', $user) }}">
                                        Ver extrato
                                    </a>
                                </div>
                            </form>
                        </x-ui.card-body>
                    </x-ui.card>
                @endif
            @endif


            <x-ui.card>
                <x-ui.card-header>
                    {{ $editing ? 'Como funciona' : 'Próximos passos' }}
                </x-ui.card-header>
                <x-ui.card-body>
                    <ul class="mb-0 ps-3 form-text">
                        @if ($editing)
                            <li class="mb-2">
                                A conta do atendente é criada já verificada por e-mail; combine a senha
                                provisória por um canal seguro.
                            </li>
                            <li class="mb-2">
                                Alterar a senha ou o e-mail passa a valer no próximo acesso. As mudanças
                                são registradas na auditoria como
                                <code>user.updated_by_operator</code>.
                            </li>
                            <li class="mb-2">
                                O preço por minuto é cobrado dos créditos do cliente a cada consulta;
                                a comissão define quanto fica com a plataforma.
                            </li>
                            <li class="mb-0">
                                O ajuste de créditos exige um motivo e aparece no extrato do cliente
                                com o identificador da operação.
                            </li>
                        @else
                            <li class="mb-2">
                                O atendente nasce com perfil <strong>offline</strong>; publique pelo
                                diretório ou peça para ele ficar online.
                            </li>
                            <li class="mb-2">
                                Defina o preço por minuto das modalidades habilitadas para que o
                                atendente apareça nas opções de consulta.
                            </li>
                            <li class="mb-0">
                                Contas criadas aqui entram verificadas; a senha provisória deve ser
                                trocada no primeiro acesso.
                            </li>
                        @endif
                    </ul>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>

