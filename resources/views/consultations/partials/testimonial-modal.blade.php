@php
    /**
     * Modal de depoimento reaproveitado em dois lugares:
     * - `consultations/show.blade.php`: abre sozinho quando a consulta termina
     *   (o JS guarda a dispensa em sessionStorage para não insistir no reload);
     * - `client/testimonials.blade.php`: aberto pelo botão "Depoimentar" de cada
     *   consulta concluída ainda sem avaliação.
     *
     * Os campos são escritos aqui, e não com `x-ui.field`, porque a página do
     * cliente renderiza um modal por consulta e os IDs precisam ser únicos.
     *
     * Variáveis esperadas: $consultation (App\Models\Consultation),
     * $attendantName (string), $modalId (string), $autoOpen (bool).
     */
    $modalId = $modalId ?? 'testimonial-modal-'.$consultation->getKey();
    $autoOpen = $autoOpen ?? false;
    $forceOpen = $errors->any()
        && (string) old('consultation_id') === (string) $consultation->getKey();
    $autoOpen = $autoOpen || $forceOpen;
    $ratingId = $modalId.'-rating';
    $messageId = $modalId.'-message';

    // A nota só é reposta quando foi este o formulário que falhou: em
    // `client/testimonials.blade.php` há um modal por consulta e `old('rating')`
    // ligaria a mesma estrela em todos eles.
    $selectedRating = $forceOpen ? (string) old('rating') : '';
@endphp

<div
    class="modal fade"
    id="{{ $modalId }}"
    tabindex="-1"
    aria-labelledby="{{ $modalId }}-title"
    aria-hidden="true"
    @if ($autoOpen) data-testimonial-prompt data-consultation-id="{{ $consultation->getKey() }}" @endif
    @if ($forceOpen) data-force-open="true" @endif
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('client.testimonials.store', $consultation) }}">
                @csrf
                <input type="hidden" name="consultation_id" value="{{ $consultation->getKey() }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="{{ $modalId }}-title">Como foi sua consulta?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <p class="text-ivory-dim">
                        Conte como foi o atendimento com <strong>{{ $attendantName }}</strong>.
                        Seu depoimento aparece no perfil público do atendente e ajuda outras
                        pessoas a escolherem com quem conversar.
                    </p>

                    <div class="mb-3">
                        <span class="form-label d-block" id="{{ $ratingId }}-label">Nota</span>
                        {{--
                            Estrelas clicáveis: os rádios nativos continuam sendo o
                            campo enviado (`name="rating"`), então validação, teclado e
                            leitores de tela funcionam sem depender do JS. O JS apenas
                            acende as estrelas até a nota escolhida.
                        --}}
                        <div
                            class="star-rating @error('rating') is-invalid @enderror"
                            role="radiogroup"
                            aria-labelledby="{{ $ratingId }}-label"
                            data-star-rating
                        >
                            @for ($stars = 1; $stars <= 5; $stars++)
                                <input
                                    class="star-rating__input visually-hidden"
                                    type="radio"
                                    id="{{ $ratingId }}-{{ $stars }}"
                                    name="rating"
                                    value="{{ $stars }}"
                                    autocomplete="off"
                                    required
                                    @checked($selectedRating === (string) $stars)
                                >
                                <label
                                    class="star-rating__star"
                                    for="{{ $ratingId }}-{{ $stars }}"
                                    data-star-rating-star="{{ $stars }}"
                                >
                                    <x-ui.icon name="star" width="30" height="30" />
                                    <span class="visually-hidden">{{ $stars }} {{ $stars === 1 ? 'estrela' : 'estrelas' }}</span>
                                </label>
                            @endfor
                            <span class="star-rating__value" data-star-rating-value aria-hidden="true">Escolha de 1 a 5 estrelas</span>
                        </div>
                        @error('rating')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="{{ $messageId }}">Depoimento</label>
                        <textarea
                            class="form-control @error('message') is-invalid @enderror"
                            id="{{ $messageId }}"
                            name="message"
                            rows="4"
                            maxlength="500"
                            required
                            placeholder="O que mais marcou na sua consulta?"
                        >{{ old('message') }}</textarea>
                        @error('message')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-text">
                            Máximo de 500 caracteres. Não inclua telefone, e-mail, redes sociais
                            ou outros dados pessoais — o depoimento fica público.
                        </p>
                    </div>

                    <p class="small text-ivory-dim mb-0">
                        O depoimento é publicado assim que enviado e não pode ser editado depois.
                        Você pode escrever depois em <strong>Depoimentos</strong>, no menu da sua conta.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Agora não</button>
                    <x-ui.button type="submit" variant="primary">Enviar depoimento</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
