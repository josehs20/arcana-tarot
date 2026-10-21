<footer class="footer-arcana py-5 mt-auto" role="contentinfo">
    <div class="container-wide">
        <div class="row g-4">
            <div class="col-lg-5">
                <h5 class="text-gold mb-3">Arcana Tarot</h5>
                <p class="text-ivory-muted small mb-3">
                    Consultas de tarô online com atendentes especializados. Conecte-se com o místico.
                </p>
                <p class="text-ivory-dim small mb-0">
                    Um ambiente reservado para suas perguntas, com valor e disponibilidade
                    exibidos antes de qualquer solicitação.
                </p>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="text-ivory mb-3">Navegação</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="{{ route('home') }}">Início</a></li>
                    <li class="mb-2"><a href="{{ route('attendants.index') }}">Atendentes</a></li>
                    <li class="mb-2"><a href="{{ route('home') }}#como-funciona">Como funciona</a></li>
                    <li class="mb-2"><a href="{{ route('home') }}#faq">Dúvidas frequentes</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="text-ivory mb-3">Institucional</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="{{ route('work-with-us.index') }}">Trabalhe conosco</a></li>
                    <li class="mb-2"><a href="{{ route('contact') }}">Contato</a></li>
                </ul>
            </div>

            <div class="col-12 col-lg-3">
                <h6 class="text-ivory mb-3">Legal</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="{{ route('legal.terms') }}">Termos de uso</a></li>
                    <li class="mb-2"><a href="{{ route('legal.privacy') }}">Política de privacidade</a></li>
                </ul>
                <p class="text-ivory-dim small mb-0">Documentos em revisão jurídica.</p>
            </div>
        </div>

        <hr class="my-4" style="border-color: rgba(201, 168, 76, 0.15);">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <p class="text-ivory-muted small mb-0">&copy; {{ date('Y') }} Arcana Tarot. Todos os direitos reservados.</p>
            <p class="text-ivory-muted small mb-0">Versão {{ config('app.version', '1.0.0') }}</p>
        </div>
    </div>
</footer>
