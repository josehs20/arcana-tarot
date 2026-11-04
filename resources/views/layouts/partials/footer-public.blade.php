@inject('siteContent', 'App\Support\SiteContent')

@php
    $brandLogo = $siteContent->logoUrl();
@endphp

<footer class="footer-arcana py-5 mt-auto" role="contentinfo">
    <div class="container-wide">
        <div class="row g-4">
            <div class="col-lg-5">
                <a class="footer-arcana__brand" href="{{ route('home') }}" aria-label="{{ $siteContent->name() }} - Início">
                    @if ($brandLogo)
                        <img src="{{ $brandLogo }}" alt="" width="32" height="32">
                    @else
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                            <path d="M12 6v6l4 2"/>
                        </svg>
                    @endif
                    <span>{{ $siteContent->name() }}</span>
                </a>
                <p class="text-ivory-muted small mb-3 mt-3">
                    {{ $siteContent->description() }}
                </p>
                <p class="text-ivory-dim small mb-0">
                    Um ambiente reservado para suas perguntas, com valor e disponibilidade
                    exibidos antes de qualquer solicitação.
                </p>
            </div>

            <div class="col-6 col-lg-3">
                <h6 class="text-ivory mb-3">Navegação</h6>
                <ul class="list-unstyled small">
                    @foreach ($siteContent->navigation('footer') as $link)
                        <li class="mb-2">
                            <a href="{{ $link['url'] }}"
                               @if ($link['new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="text-ivory mb-3">Legal</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="{{ route('legal.terms') }}">Termos de uso</a></li>
                    <li class="mb-2"><a href="{{ route('legal.privacy') }}">Política de privacidade</a></li>
                </ul>
            </div>

            <div class="col-12 col-lg-2">
                <h6 class="text-ivory mb-3">Ajuda</h6>
                <p class="text-ivory-dim small mb-3">
                    Dúvidas sobre créditos, sessões ou privacidade?
                </p>
                <a class="btn btn-ghost btn-sm" href="{{ route('contact') }}">Falar com a equipe</a>
            </div>
        </div>

        <hr class="my-4 footer-arcana__divider">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <p class="text-ivory-muted small mb-0">&copy; {{ date('Y') }} {{ $siteContent->name() }}. Todos os direitos reservados.</p>
            <p class="text-ivory-muted small mb-0">Versão {{ config('app.version', '1.0.0') }}</p>
        </div>
    </div>
</footer>
