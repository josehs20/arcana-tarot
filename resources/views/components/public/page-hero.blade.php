{{--
    Cabeçalho compacto das páginas públicas internas.

    Uso:
        <x-public.page-hero
            eyebrow="Diretório"
            title="Quem lê as suas cartas"
            description="..."
            current="Atendentes"
        />

    Páginas de segundo nível informam o pai da trilha:
        <x-public.page-hero
            title="..."
            parent-label="Atendentes"
            :parent-route="route('attendants.index')"
        />

    Ações opcionais entram pelo slot `actions`.
--}}
@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'current' => null,
    'parentLabel' => null,
    'parentRoute' => null,
])

<section class="arcana-hero arcana-hero--compact arcana-stars">
    <div class="container-wide">
        <nav aria-label="Trilha de navegação">
            <ol class="breadcrumb-arcana">
                <li><a href="{{ route('home') }}">Início</a></li>
                <li aria-hidden="true">/</li>
                @if ($parentLabel && $parentRoute)
                    <li><a href="{{ $parentRoute }}">{{ $parentLabel }}</a></li>
                    <li aria-hidden="true">/</li>
                @endif
                <li aria-current="page">{{ $current ?? $title }}</li>
            </ol>
        </nav>

        @if ($eyebrow)
            <p class="arcana-eyebrow mb-2">{{ $eyebrow }}</p>
        @endif
        <h1 class="arcana-hero__title">{{ $title }}</h1>
        <div class="arcana-hero__divider" aria-hidden="true"></div>
        @if ($description)
            <p class="arcana-lead mb-0">{{ $description }}</p>
        @endif

        @isset($actions)
            <div class="d-flex flex-wrap gap-3 mt-4">{{ $actions }}</div>
        @endisset
    </div>
</section>
