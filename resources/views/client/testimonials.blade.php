<x-layouts.panel variant="panel" title="Meus depoimentos | Arcana Tarot">
    <x-layouts.page-header
        title="Meus depoimentos"
        subtitle="Avalie os atendimentos concluídos e acompanhe o que já foi publicado nos perfis."
    >
        <x-slot:actions>
            <a class="btn btn-ghost" href="{{ route('client.dashboard') }}">Voltar ao painel</a>
            <a class="btn btn-primary" href="{{ route('attendants.index') }}">Encontrar atendente</a>
        </x-slot:actions>
    </x-layouts.page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-4">
            <div class="stat-card">
                <p class="stat-card__label">Depoimentos enviados</p>
                <p class="stat-card__value">{{ $submitted->count() }}</p>
                <p class="stat-card__hint">Total publicado por você</p>
            </div>
        </div>
        <div class="col-6 col-lg-4">
            <div class="stat-card">
                <p class="stat-card__label">Nota média dada</p>
                <p class="stat-card__value">{{ $averageRating === null ? '—' : number_format($averageRating, 1, ',', '.') }}</p>
                <p class="stat-card__hint">De 1 a 5 estrelas</p>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="stat-card">
                <p class="stat-card__label">Aguardando avaliação</p>
                <p class="stat-card__value">{{ $pending->count() }}</p>
                <p class="stat-card__hint">Consultas concluídas sem depoimento</p>
            </div>
        </div>
    </div>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O depoimento é publicado no perfil público do atendente assim que você envia e não pode ser
        editado depois. A administração pode ocultá-lo se ele violar as regras da plataforma.
        Não inclua telefone, e-mail, redes sociais ou outros dados pessoais.
    </x-ui.alert>

    <x-ui.card class="mb-4">
        <x-ui.card-header>
            <h2 class="h6 mb-0">Aguardando seu depoimento</h2>
        </x-ui.card-header>

        @if ($pending->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="★"
                    title="Nenhuma consulta pendente de avaliação"
                    text="Quando um atendimento for concluído, ele aparece aqui para você contar como foi."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Consultas concluídas que ainda podem ser avaliadas</caption>
                    <thead>
                        <tr>
                            <th scope="col">Concluída em</th>
                            <th scope="col">Atendente</th>
                            <th scope="col">Modalidade</th>
                            <th scope="col">Duração</th>
                            <th scope="col" class="text-end">Depoimento</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $consultation)
                            @php
                                $attendantName = $consultation->attendant->attendantProfile?->display_name
                                    ?? $consultation->attendant->name;
                                $pendingModalId = 'testimonial-modal-'.$consultation->getKey();
                            @endphp
                            <tr>
                                <td class="text-nowrap">
                                    {{ ($consultation->ended_at ?? $consultation->updated_at)?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td>{{ $attendantName }}</td>
                                <td>
                                    <x-ui.badge variant="gold">{{ $consultation->modalityLabel() }}</x-ui.badge>
                                </td>
                                <td class="text-nowrap">{{ $consultation->durationLabel() ?? '—' }}</td>
                                <td class="text-end">
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#{{ $pendingModalId }}"
                                    >
                                        Depoimentar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card>
        <x-ui.card-header class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Depoimentos enviados</h2>
            <span class="small text-ivory-dim">{{ $submitted->count() }} registro(s)</span>
        </x-ui.card-header>

        @if ($submitted->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Você ainda não avaliou nenhum atendimento"
                    text="Seus depoimentos ficam listados aqui com a nota e o que foi publicado."
                />
            </x-ui.card-body>
        @else
            <x-ui.card-body>
                <ul class="list-unstyled mb-0">
                    @foreach ($submitted as $consultation)
                        @php
                            $testimonial = $consultation->testimonial;
                            $attendantName = $consultation->attendant->attendantProfile?->display_name
                                ?? $consultation->attendant->name;
                        @endphp
                        <li class="{{ $loop->last ? '' : 'border-bottom pb-3 mb-3' }}">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <p class="mb-1">
                                        <strong>{{ $attendantName }}</strong>
                                        <span class="text-ivory-dim">· {{ $testimonial->created_at?->format('d/m/Y H:i') }}</span>
                                    </p>
                                    <p class="mb-1" aria-label="{{ $testimonial->ratingLabel() }}">
                                        <span class="text-gold" aria-hidden="true">{{ $testimonial->ratingStars() }}</span>
                                    </p>
                                </div>
                                <div class="text-end">
                                    @if ($testimonial->isVisible())
                                        <x-ui.badge variant="success">Publicado</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">Oculto pela moderação</x-ui.badge>
                                    @endif
                                    <a
                                        class="btn btn-ghost btn-sm d-block mt-2"
                                        href="{{ route('consultations.show', $consultation) }}"
                                    >
                                        Ver consulta
                                    </a>
                                </div>
                            </div>
                            <p class="mb-0">{{ $testimonial->message }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card-body>
        @endif
    </x-ui.card>

    @foreach ($pending as $consultation)
        @include('consultations.partials.testimonial-modal', [
            'consultation' => $consultation,
            'attendantName' => $consultation->attendant->attendantProfile?->display_name
                ?? $consultation->attendant->name,
            'modalId' => 'testimonial-modal-'.$consultation->getKey(),
            'autoOpen' => false,
        ])
    @endforeach
</x-layouts.panel>
