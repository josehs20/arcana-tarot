{{-- Versão texto do disparo: mesmo conteúdo, sem HTML. --}}
@inject('siteContent', 'App\Support\SiteContent')
{!! $renderedBody !!}

--
{{ $siteContent->name() }}
{{ route('home') }}
