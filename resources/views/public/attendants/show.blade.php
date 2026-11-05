@php
    $status = $attendant->publicStatus();
    $photoUrl = $attendant->photoUrl();
    $specialties = $attendant->specialties ?? [];
    $description = $attendant->bio
        ? Str::limit($attendant->bio, 150)
        : 'Conheça o perfil de ' . $attendant->display_name . ' no Arcana Tarot.';
    $panelRoute = auth()->check()
        ? (auth()->user()->isAdmin()
            ? route('admin.dashboard')
            : (auth()->user()->isAttendant() ? route('attendant.dashboard') : route('client.dashboard')))
        : route('login');
    $availableOptions = array_values(array_filter($options, fn (array $option): bool => $option['available']));
    $defaultModality = old('modality', $availableOptions[0]['value'] ?? null);

    /*
     * Saldo do cliente e mínimo exigido para a modalidade que já vem
     * selecionada. O servidor é quem barra a solicitação sem crédito; aqui os
     * números só alimentam o aviso antecipado — o modal de saldo insuficiente,
     * aberto por `consultation-modalities.js` antes do envio.
     */
    $signedInUser = auth()->user();
    $clientBalanceCents = $signedInUser !== null && $signedInUser->isClient()
        ? (int) ($signedInUser->wallet?->balance_cents ?? 0)
        : null;
    $defaultOption = collect($availableOptions)->firstWhere('value', $defaultModality);
    $minimumCentsForDefault = $defaultOption === null || $defaultOption['price_cents'] === null
        ? null
        : $defaultOption['price_cents'] * $minimumBalanceMinutes;
@endphp

<x-layouts.public
    :title="$attendant->display_name . ' — Atendente | Arcana Tarot'"
    :description="$description"
>
    <x-public.page-hero
        eyebrow="Perfil público"
        :title="$attendant->display_name"
        :current="$attendant->display_name"
        parent-label="Atendentes"
        :parent-route="route('attendants.index')"
    />

    <section class="arcana-section">
        <div class="container-wide">
            <div class="row g-4 g-lg-5">
                <div class="col-lg-8">
                    <x-ui.card class="card-gilded">
                        <x-ui.card-body class="p-4 p-md-5">
                            <div class="profile-header">
                                <div class="profile-avatar">
                                    @if ($photoUrl)
                                        <img src="{{ $photoUrl }}" alt="Foto de {{ $attendant->display_name }}" width="112" height="112">
                                    @else
                                        <span class="profile-avatar__initials" aria-hidden="true">{{ $attendant->initials() }}</span>
                                    @endif
                                </div>

                                <div>
                                    <p class="arcana-eyebrow mb-2">Atendente de tarô</p>
                                    <p class="profile-name mb-2">{{ $attendant->display_name }}</p>
                                    <x-ui.status-badge
                                        :status="$status"
                                        :label="$status === 'busy' ? 'Em consulta' : null"
                                    />
                                </div>
                            </div>

                            <hr class="profile-divider">

                            <h2 class="h4 mb-3">Apresentação</h2>
                            <p class="profile-bio mb-0">
                                {{ $attendant->bio ?: 'Este atendente ainda não publicou uma apresentação.' }}
                            </p>

                            <h2 class="h5 mt-4 mb-3">Especialidades</h2>
                            @if ($specialties !== [])
                                <ul class="profile-specialties list-unstyled mb-0" aria-label="Especialidades">
                                    @foreach ($specialties as $specialty)
                                        <li><x-ui.badge variant="gold">{{ $specialty }}</x-ui.badge></li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-ivory-dim mb-0">Especialidades não informadas.</p>
                            @endif

                            <h2 class="h5 mt-4 mb-3">Modalidades e preços</h2>
                            <ul class="modality-list list-unstyled mb-0" aria-label="Modalidades oferecidas">
                                @foreach ($options as $option)
                                    <li class="modality-list__item {{ $option['available'] ? '' : 'is-unavailable' }}">
                                        <span class="modality-list__icon" aria-hidden="true">
                                            <x-ui.icon :name="$option['icon']" width="18" height="18" />
                                        </span>
                                        <span class="modality-list__body">
                                            <span class="modality-list__title">{{ $option['label'] }}</span>
                                            <span class="modality-list__text">{{ $option['description'] }}</span>
                                            @if (! $option['available'] && $option['reason'])
                                                <span class="modality-list__text text-warning">{{ $option['reason'] }}</span>
                                            @endif
                                        </span>
                                        <span class="modality-list__price">
                                            @if ($option['price_label'])
                                                {{ $option['price_label'] }} <small>/min</small>
                                            @else
                                                <span class="text-ivory-dim">Não configurado</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-ui.card-body>
                    </x-ui.card>

                    {{--
                        Depoimentos de atendimentos concluídos. A média e a
                        contagem vêm apenas dos depoimentos visíveis; nomes são
                        reduzidos a primeiro nome + inicial pelo model.
                    --}}
                    <x-ui.card id="depoimentos" class="card-gilded mt-4">
                        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h2 class="h5 mb-0">Depoimentos</h2>
                            @if ($testimonialAverage !== null)
                                <span class="testimonial-summary">
                                    <span class="text-gold" aria-hidden="true">
                                        {{ str_repeat('★', (int) round($testimonialAverage)) }}{{ str_repeat('☆', 5 - (int) round($testimonialAverage)) }}
                                    </span>
                                    <span class="ms-2">
                                        {{ number_format($testimonialAverage, 1, ',', '.') }} de 5 ·
                                        {{ $testimonialCount }} {{ $testimonialCount === 1 ? 'depoimento' : 'depoimentos' }}
                                    </span>
                                </span>
                            @endif
                        </x-ui.card-header>

                        @if ($testimonials->isEmpty())
                            <x-ui.card-body>
                                <x-ui.empty-state
                                    icon="★"
                                    title="Ainda não há depoimentos"
                                    text="Este atendente ainda não recebeu avaliações públicas. Depoimentos aparecem aqui depois de consultas concluídas."
                                />
                            </x-ui.card-body>
                        @else
                            <x-ui.card-body>
                                <ul class="testimonial-list list-unstyled mb-0">
                                    @foreach ($testimonials as $testimonial)
                                        <li class="testimonial-list__item">
                                            <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                                                <p class="testimonial-list__author mb-1">
                                                    {{ $testimonial->clientDisplayName() }}
                                                </p>
                                                <p class="testimonial-list__meta mb-1">
                                                    <span class="text-gold" aria-label="{{ $testimonial->ratingLabel() }}">
                                                        <span aria-hidden="true">{{ $testimonial->ratingStars() }}</span>
                                                    </span>
                                                    <span class="ms-2">{{ $testimonial->created_at?->format('d/m/Y') ?? '' }}</span>
                                                </p>
                                            </div>
                                            <p class="testimonial-list__text mb-0">{{ $testimonial->message }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </x-ui.card-body>

                            <x-ui.card-footer>
                                {{ $testimonials->links() }}
                            </x-ui.card-footer>
                        @endif
                    </x-ui.card>
                </div>

                <aside class="col-lg-4">
                    <x-ui.card class="card-gilded">
                        <x-ui.card-header>
                            <h2 class="h6 mb-0">Iniciar consulta</h2>
                        </x-ui.card-header>
                        <x-ui.card-body>
                            <p class="profile-status-hint mb-3">
                                @if ($status === 'online')
                                    Disponível para novas solicitações.
                                @elseif ($status === 'busy')
                                    Em consulta neste momento.
                                @else
                                    Não está recebendo novas solicitações agora.
                                @endif
                            </p>

                            @if ($availableOptions === [])
                                <x-ui.alert type="warning" :dismissible="false">
                                    Nenhuma modalidade está disponível para este atendente agora.
                                </x-ui.alert>
                            @endif

                            @if ($status === 'online' && $availableOptions !== [])
                                @guest
                                    <ul class="modality-list list-unstyled mb-3" aria-label="Preços por modalidade">
                                        @foreach ($availableOptions as $option)
                                            <li class="modality-list__item">
                                                <span class="modality-list__body">
                                                    <span class="modality-list__title">{{ $option['label'] }}</span>
                                                    <span class="modality-list__text">{{ $option['description'] }}</span>
                                                </span>
                                                <span class="modality-list__price">{{ $option['price_label'] }} <small>/min</small></span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <a class="btn btn-primary w-100" href="{{ route('login') }}">Entrar para solicitar</a>
                                    <a class="btn btn-ghost w-100 mt-2" href="{{ route('register') }}">Criar conta</a>
                                    <p class="small text-ivory-dim mt-3 mb-0">
                                        Você precisa estar autenticado para solicitar uma consulta.
                                    </p>
                                @else
                                    @if (auth()->user()->isClient() && auth()->user()->is_active)
                                        <form
                                            method="POST"
                                            action="{{ route('client.consultations.store') }}"
                                            data-modality-form
                                            data-modality-audio="{{ collect($options)->firstWhere('value', 'audio')['available'] ?? false ? '1' : '0' }}"
                                            data-modality-video="{{ collect($options)->firstWhere('value', 'video')['available'] ?? false ? '1' : '0' }}"
                                            data-balance-cents="{{ $clientBalanceCents ?? 0 }}"
                                            data-minimum-minutes="{{ $minimumBalanceMinutes }}"
                                        >
                                            @csrf
                                            <input type="hidden" name="attendant_id" value="{{ $attendant->user_id }}">

                                            <fieldset class="modality-picker">
                                                <legend class="visually-hidden">Escolha a modalidade</legend>

                                                @foreach ($options as $option)
                                                    <label @class(['modality-option', 'is-disabled' => ! $option['available']])>
                                                        <input
                                                            class="modality-option__input"
                                                            type="radio"
                                                            name="modality"
                                                            value="{{ $option['value'] }}"
                                                            data-requires-video="{{ $option['requires_video'] ? '1' : '0' }}"
                                                            data-price-cents="{{ $option['price_cents'] ?? '' }}"
                                                            @checked($defaultModality === $option['value'])
                                                            @disabled(! $option['available'])
                                                            required
                                                        >
                                                        <span class="modality-option__icon" aria-hidden="true">
                                                            <x-ui.icon :name="$option['icon']" />
                                                        </span>
                                                        <span class="modality-option__body">
                                                            <span class="modality-option__title">{{ $option['label'] }}</span>
                                                            <span class="modality-option__price">
                                                                @if ($option['price_label'])
                                                                    {{ $option['price_label'] }} <small>/ minuto</small>
                                                                @else
                                                                    Preço não configurado
                                                                @endif
                                                            </span>
                                                            <span class="modality-option__hint">
                                                                {{ $option['reason'] ?? $option['description'] }}
                                                            </span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </fieldset>

                                            @error('modality')
                                                <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                                            @enderror

                                            {{--
                                                Erros devolvidos pelo servidor para esta solicitação
                                                (saldo recalculado no aceite, consulta já aberta…).
                                                Saldo insuficiente normalmente é barrado antes pelo
                                                modal de saldo desta página; esta mensagem é a rede
                                                de segurança de quem está sem JavaScript ou com a
                                                página desatualizada. O layout público usa
                                                `showErrors = false`, então o erro precisa ser
                                                renderizado aqui.
                                            --}}
                                            @error('consultation')
                                                <x-ui.alert type="danger" :dismissible="false" class="py-2 px-3 mb-2">
                                                    {{ $message }}
                                                    <a class="alert-link" href="{{ route('client.credits.index') }}">Comprar créditos</a>
                                                </x-ui.alert>
                                            @enderror

                                            <div class="alert alert-warning py-2 px-3 mt-3 d-none" data-modality-notice role="status" aria-live="polite"></div>

                                            <p class="small text-ivory-dim mt-3 mb-2">
                                                Seu saldo: <strong>{{ \App\Support\Money::format($clientBalanceCents ?? 0) }}</strong>
                                                · mínimo para iniciar:
                                                <strong data-balance-minimum-label>
                                                    {{ $minimumCentsForDefault === null ? '—' : \App\Support\Money::format($minimumCentsForDefault) }}
                                                </strong>
                                            </p>

                                            <p class="small text-ivory-dim mt-3">
                                                A cobrança é por minuto iniciado, calculada e confirmada pelo servidor.
                                                Áudio e vídeo pedem permissão de microfone (e câmera) antes de conectar.
                                            </p>

                                            <x-ui.button type="submit" variant="primary" block data-modality-submit>
                                                Confirmar e solicitar
                                            </x-ui.button>
                                        </form>
                                        <p class="small text-ivory-dim mt-3 mb-0">
                                            O preço é validado pelo servidor ao iniciar a consulta. Você pode cancelar
                                            enquanto aguarda o aceite.
                                        </p>

                                        {{--
                                            Aviso de saldo insuficiente.

                                            `consultation-modalities.js` compara o saldo renderizado no
                                            formulário (`data-balance-cents`) com o preço da modalidade
                                            escolhida e, antes de enviar, abre este modal em vez de deixar o
                                            servidor recusar o pedido. Os valores abaixo são o estado inicial
                                            (modalidade padrão); o JS reescreve com os números da seleção. O
                                            servidor continua sendo a autoridade: sem JavaScript o erro de
                                            validação aparece no formulário acima.
                                        --}}
                                        <div
                                            class="modal fade"
                                            id="insufficient-balance-modal"
                                            tabindex="-1"
                                            aria-labelledby="insufficient-balance-modal-title"
                                            aria-hidden="true"
                                            data-balance-modal
                                        >
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h2 class="modal-title h5" id="insufficient-balance-modal-title">
                                                            Saldo insuficiente
                                                        </h2>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                                    </div>

                                                    <div class="modal-body">
                                                        <p class="mb-2">
                                                            Você escolheu
                                                            <strong data-balance-modal-modality>{{ $defaultOption['label'] ?? 'esta modalidade' }}</strong>,
                                                            que exige no mínimo
                                                            <strong data-balance-modal-minimum>
                                                                {{ $minimumCentsForDefault === null ? '—' : \App\Support\Money::format($minimumCentsForDefault) }}
                                                            </strong>
                                                            ({{ $minimumBalanceMinutes }} minuto(s) de atendimento).
                                                        </p>
                                                        <p class="mb-2">
                                                            Seu saldo atual é
                                                            <strong data-balance-modal-current>{{ \App\Support\Money::format($clientBalanceCents ?? 0) }}</strong>.
                                                            A solicitação não foi enviada ao atendente e nada foi cobrado.
                                                        </p>
                                                        <p class="small text-ivory-dim mb-0">
                                                            A cobrança é por minuto iniciado, calculada e confirmada pelo servidor.
                                                        </p>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Agora não</button>
                                                        <a class="btn btn-primary" href="{{ route('client.credits.index') }}">
                                                            Comprar créditos
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <a class="btn btn-primary w-100" href="{{ $panelRoute }}">Ir para o meu painel</a>
                                        <p class="small text-ivory-dim mt-3 mb-0">
                                            Apenas clientes ativos podem solicitar consultas. Atendentes e administradores
                                            acompanham os atendimentos pelos próprios painéis.
                                        </p>
                                    @endif
                                @endguest
                            @elseif ($status !== 'online')
                                <a class="btn btn-primary w-100" href="{{ route('attendants.index') }}">Ver outros atendentes</a>
                                <p class="small text-ivory-dim mt-3 mb-0">
                                    Você pode conhecer o perfil e os preços, mas a solicitação só é liberada
                                    quando o atendente está online.
                                </p>
                            @endif
                        </x-ui.card-body>
                    </x-ui.card>

                    <a class="btn btn-ghost w-100 mt-3" href="{{ route('attendants.index') }}">
                        Voltar ao diretório
                    </a>
                </aside>
            </div>
        </div>
    </section>
</x-layouts.public>
