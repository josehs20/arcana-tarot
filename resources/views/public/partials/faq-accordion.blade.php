@if (empty($faqs))
    <x-ui.empty-state
        icon="?"
        title="Perguntas em atualização"
        text="Nenhuma pergunta está publicada neste momento. Fale com a equipe para esclarecer suas dúvidas."
    />
@else
    <div class="accordion accordion-arcana" id="faq-acordeao">
        @foreach ($faqs as $i => $faq)
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-{{ $i }}"
                            aria-expanded="false"
                            aria-controls="faq-{{ $i }}">
                        {{ $faq['question'] }}
                    </button>
                </h3>
                <div id="faq-{{ $i }}" class="accordion-collapse collapse"
                     data-bs-parent="#faq-acordeao">
                    <div class="accordion-body text-ivory-muted">
                        {{ $faq['answer'] }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
