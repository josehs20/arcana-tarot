{{--
    Gráfico SVG acessível, sem dependências de JavaScript.

    Uso:
        <x-ui.chart :labels="$trend['labels']" :values="$trend['values']"
            series-label="Consultas por dia" unit="count" type="line" />
        <x-ui.chart ... unit="currency" type="bar" />
--}}
@props([
    'labels' => [],
    'values' => [],
    'seriesLabel' => 'Série',
    'unit' => 'count',
    'type' => 'line',
    'period' => null,
])

@php
    use App\Support\Money;

    $labels = array_values($labels);
    $values = array_map('intval', array_values($values));
    $count = count($values);
    $type = $type === 'bar' ? 'bar' : 'line';
    $hasData = $count > 0 && array_sum(array_map('abs', $values)) > 0;

    $format = fn (int $value): string => $unit === 'currency'
        ? Money::format($value)
        : number_format($value, 0, ',', '.');

    $width = 720;
    $height = 260;
    $paddingLeft = 86;
    $paddingRight = 16;
    $paddingTop = 18;
    $paddingBottom = 34;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;

    $maxValue = $count > 0 ? max(0, max($values)) : 0;
    $minValue = $count > 0 ? min(0, min($values)) : 0;
    $range = max(1, $maxValue - $minValue);

    $x = function (int $index) use ($count, $type, $paddingLeft, $plotWidth): float {
        if ($type === 'bar') {
            return $paddingLeft + (($index + 0.5) * ($plotWidth / max(1, $count)));
        }

        return $count <= 1
            ? $paddingLeft + ($plotWidth / 2)
            : $paddingLeft + ($index * ($plotWidth / ($count - 1)));
    };

    $y = fn (int $value): float => $paddingTop + (($maxValue - $value) / $range) * $plotHeight;
    $zeroY = $y(0);
    $chartId = 'chart-'.substr(md5($seriesLabel.json_encode($values)), 0, 8);
    $step = max(1, (int) ceil($count / 7));
    $barWidth = max(3, ($plotWidth / max(1, $count)) * 0.55);

    $path = [];

    foreach ($values as $index => $value) {
        $path[] = ($index === 0 ? 'M' : 'L').' '.round($x($index), 2).' '.round($y($value), 2);
    }

    $linePath = implode(' ', $path);
    $areaPath = $count > 0
        ? $linePath.' L '.round($x($count - 1), 2).' '.round($zeroY, 2).' L '.round($x(0), 2).' '.round($zeroY, 2).' Z'
        : '';
    $ticks = array_values(array_unique([$maxValue, (int) round(($maxValue + $minValue) / 2), $minValue], SORT_REGULAR));
@endphp


<div {{ $attributes->merge(['class' => 'chart']) }}>
    @if (! $hasData)
        <div class="chart-empty" role="status">
            <x-ui.icon name="activity" width="28" height="28" />
            <p class="mb-0">Sem dados no período</p>
            @if ($period)
                <p class="chart-empty__period">{{ $period }}</p>
            @endif
        </div>
    @else
        <svg
            class="chart__svg"
            viewBox="0 0 {{ $width }} {{ $height }}"
            role="img"
            aria-label="{{ $seriesLabel }}: {{ $format(array_sum($values)) }} no total{{ $period ? ', '.$period : '' }}"
        >
            <defs>
                <linearGradient id="{{ $chartId }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="var(--arcana-gold)" stop-opacity=".28" />
                    <stop offset="100%" stop-color="var(--arcana-gold)" stop-opacity="0" />
                </linearGradient>
            </defs>

            @foreach ($ticks as $tick)
                @php $tickY = $y((int) $tick); @endphp
                <line class="chart__grid" x1="{{ $paddingLeft }}" y1="{{ round($tickY, 2) }}" x2="{{ $width - $paddingRight }}" y2="{{ round($tickY, 2) }}" />
                <text class="chart__axis-label" x="{{ $paddingLeft - 10 }}" y="{{ round($tickY, 2) }}" text-anchor="end" dominant-baseline="middle">{{ $format((int) $tick) }}</text>
            @endforeach

            <line class="chart__baseline" x1="{{ $paddingLeft }}" y1="{{ round($zeroY, 2) }}" x2="{{ $width - $paddingRight }}" y2="{{ round($zeroY, 2) }}" />

            @if ($type === 'line')
                <path class="chart__area" d="{{ $areaPath }}" fill="url(#{{ $chartId }})" />
                <path class="chart__line" d="{{ $linePath }}" />

                @foreach ($values as $index => $value)
                    <circle class="chart__point" cx="{{ round($x($index), 2) }}" cy="{{ round($y($value), 2) }}" r="4">
                        <title>{{ $labels[$index] ?? '' }}: {{ $format($value) }}</title>
                    </circle>
                @endforeach
            @else
                @foreach ($values as $index => $value)
                    @php
                        $valueY = $y($value);
                        $barY = $value >= 0 ? $valueY : $zeroY;
                        $barHeight = max(1, abs($zeroY - $valueY));
                    @endphp
                    <rect class="chart__bar{{ $value < 0 ? ' chart__bar--negative' : '' }}" x="{{ round($x($index) - ($barWidth / 2), 2) }}" y="{{ round($barY, 2) }}" width="{{ round($barWidth, 2) }}" height="{{ round($barHeight, 2) }}" rx="3">
                        <title>{{ $labels[$index] ?? '' }}: {{ $format($value) }}</title>
                    </rect>
                @endforeach
            @endif

            @foreach ($labels as $index => $label)
                @if ($index % $step === 0 || $index === $count - 1)
                    <text class="chart__x-label" x="{{ round($x($index), 2) }}" y="{{ $height - 10 }}" text-anchor="middle">{{ $label }}</text>
                @endif
            @endforeach
        </svg>

        <table class="visually-hidden">
            <caption>{{ $seriesLabel }}</caption>
            <thead><tr><th scope="col">Dia</th><th scope="col">Valor</th></tr></thead>
            <tbody>
                @foreach ($values as $index => $value)
                    <tr><th scope="row">{{ $labels[$index] ?? '' }}</th><td>{{ $format($value) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
