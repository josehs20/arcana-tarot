{{--
    Overrides de aparência (cores/fontes) configurados no admin.

    Inclua SEMPRE depois do @vite para vencer a ordem dos estilos do bundle.
    Só renderiza algo quando há personalização; caso contrário o design padrão
    de _theme.scss continua valendo.
--}}
@php($appearanceCss = app(\App\Support\Appearance::class)->css())
@if ($appearanceCss !== '')
    <style id="arcana-appearance">{!! $appearanceCss !!}</style>
@endif
