<x-layouts.panel variant="admin" title="Depoimentos | Arcana Tarot">
    <x-layouts.page-header
        title="Depoimentos"
        subtitle="Moderação dos depoimentos publicados nos perfis dos atendentes: ocultar e republicar."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Depoimentos</p>
                <p class="stat-card__value">{{ $totals['total'] }}</p>
                <p class="stat-card__hint">No filtro atual</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Publicados</p>
                <p class="stat-card__value">{{ $totals['published'] }}</p>
                <p class="stat-card__hint">Visíveis nos perfis públicos</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Ocultos</p>
                <p class="stat-card__value">{{ $totals['hidden'] }}</p>
                <p class="stat-card__hint">Fora dos perfis até republicar</p>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <p class="stat-card__label">Nota média</p>
                <p class="stat-card__value">{{ number_format($totals['average'], 1, ',', '.') }}</p>
                <p class="stat-card__hint">De 1 a 5 estrelas no filtro</p>
            </div>
        </div>
    </div>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O cliente não edita o depoimento depois de enviar. Aqui a moderação apenas oculta o que
        viola as regras ou republica o que foi ocultado por engano. Toda ação fica registrada na
        auditoria com o administrador responsável.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.testimonials.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        id="q"
                        name="q"
                        type="search"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Texto, cliente ou atendente"
                    >
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="status">Situação</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todas</option>
                        <option value="published" @selected(($filters['status'] ?? '') === 'published')>Publicados</option>
                        <option value="hidden" @selected(($filters['status'] ?? '') === 'hidden')>Ocultos</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="rating">Nota</label>
                    <select class="form-select" id="rating" name="rating">
                        <option value="">Todas</option>
                        @for ($stars = 5; $stars >= 1; $stars--)
                            <option value="{{ $stars }}" @selected((string) ($filters['rating'] ?? '') === (string) $stars)>
                                {{ $stars }} estrela(s)
                            </option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="attendant_id">Atendente</label>
                    <select class="form-select" id="attendant_id" name="attendant_id">
                        <option value="">Todos</option>
                        @foreach ($attendants as $attendant)
                            <option
                                value="{{ $attendant->id }}"
                                @selected((string) ($filters['attendant_id'] ?? '') === (string) $attendant->id)
                            >
                                {{ $attendant->attendantProfile?->display_name ?? $attendant->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.testimonials.index') }}">Limpar filtros</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Depoimentos recebidos</h2>
            <span class="small text-ivory-dim">{{ $testimonials->total() }} resultado(s)</span>
        </x-ui.card-header>

        @if ($testimonials->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="★"
                    title="Nenhum depoimento encontrado"
                    text="Ajuste os filtros ou aguarde os primeiros depoimentos das consultas concluídas."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Depoimentos enviados pelos clientes após consultas concluídas</caption>
                    <thead>
                        <tr>
                            <th scope="col">Depoimento</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Atendente</th>
                            <th scope="col">Enviado em</th>
                            <th scope="col">Situação</th>
                            <th scope="col" class="text-end">Moderação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($testimonials as $testimonial)
                            @php
                                $attendantName = $testimonial->attendant?->attendantProfile?->display_name
                                    ?? $testimonial->attendant?->name
                                    ?? 'Atendente removido';
                            @endphp
                            <tr>
                                <td style="min-width: 22rem">
                                    <p class="mb-1" aria-label="{{ $testimonial->ratingLabel() }}">
                                        <span class="text-gold" aria-hidden="true">{{ $testimonial->ratingStars() }}</span>
                                    </p>
                                    <p class="mb-1">{{ $testimonial->message }}</p>
                                    @if ($testimonial->consultation)
                                        <a
                                            class="link-quiet small"
                                            href="{{ route('consultations.show', $testimonial->consultation) }}"
                                        >
                                            Consulta #{{ $testimonial->consultation->getKey() }}
                                        </a>
                                    @endif
                                </td>
                                <td>
                                    {{ $testimonial->client?->name ?? 'Cliente removido' }}
                                    <span class="form-text d-block">
                                        Exibido como "{{ $testimonial->clientDisplayName() }}"
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <a
                                        class="link-quiet"
                                        href="{{ route('attendants.show', $testimonial->attendant_id) }}"
                                    >
                                        {{ $attendantName }}
                                    </a>
                                </td>
                                <td class="text-nowrap">
                                    {{ $testimonial->created_at?->format('d/m/Y H:i') ?? '—' }}
                                    @if ($testimonial->is_hidden)
                                        <span class="form-text d-block">
                                            Oculto em {{ $testimonial->hidden_at?->format('d/m/Y H:i') ?? '—' }}
                                            @if ($testimonial->hiddenBy)
                                                por {{ $testimonial->hiddenBy->name }}
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($testimonial->isVisible())
                                        <x-ui.badge variant="success">Publicado</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">Oculto</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($testimonial->isVisible())
                                        <form
                                            method="POST"
                                            action="{{ route('admin.testimonials.hide', $testimonial) }}"
                                            class="d-inline"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <x-ui.button type="submit" variant="ghost" size="sm">Ocultar</x-ui.button>
                                        </form>
                                    @else
                                        <form
                                            method="POST"
                                            action="{{ route('admin.testimonials.publish', $testimonial) }}"
                                            class="d-inline"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <x-ui.button type="submit" variant="primary" size="sm">Republicar</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $testimonials->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>
