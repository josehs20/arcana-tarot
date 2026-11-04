{{--
    Imagem própria da seção (upload feito em "Conteúdo do site").

    O topo da home (hero) tem layout próprio e não usa esta parcial. Nas demais
    seções a imagem aparece como faixa entre o cabeçalho e o conteúdo.

    Variável opcional: $class — classes extras aplicadas ao <figure>.
--}}
@if (filled($section['image_url'] ?? null))
    <figure class="arcana-section__media {{ $class ?? '' }}">
        <img
            class="arcana-section__media-image"
            src="{{ $section['image_url'] }}"
            alt="{{ $section['image_alt'] ?? '' }}"
            loading="lazy"
        >
    </figure>
@endif
