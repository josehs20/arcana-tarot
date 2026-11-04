{{--
    Texto principal de uma seção vinda do CMS.

    Só é renderizado quando a seção tem `body` gravado pelo painel. As quebras
    de linha digitadas no formulário são preservadas: o texto é escapado antes
    do `nl2br`, então continua seguro contra HTML injetado.

    Variável opcional: $class — classes extras aplicadas ao parágrafo.
--}}
@if (filled($section['body'] ?? null))
    <p class="arcana-section__body {{ $class ?? '' }}">{!! nl2br(e($section['body'])) !!}</p>
@endif
