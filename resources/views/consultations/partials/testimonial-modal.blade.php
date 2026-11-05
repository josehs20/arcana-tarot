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
                        <label class="form-label" for="{{ $ratingId }}">Nota</label>
                        <select
                            class="form-select @error('rating') is-invalid @enderror"
                            id="{{ $ratingId }}"
                            name="rating"
                            required
                        >
                            <option value="">Escolha de 1 a 5 estrelas</option>
                            @for ($stars = 5; $stars >= 1; $stars--)
                                <option value="{{ $stars }}" @selected((string) old('rating') === (string) $stars)>
                                    {{ str_repeat('★', $stars) }} — {{ $stars }} {{ $stars === 1 ? 'estrela' : 'estrelas' }}
                                </option>
                            @endfor
                        </select>
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
