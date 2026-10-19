@php
    // A point's 'value' may be null — a period with no real data (e.g. no
    // sales that month), not a real zero. Excluded from min/max so one gap
    // month doesn't flatten the whole y-axis range toward 0, and excluded
    // from the drawn line/dots/labels entirely (see $segments below) rather
    // than plotted as a misleading drop to zero. Confirmed live 2026-10-05
    // (CUS-998, Profitability charts): idle months used to render as a
    // literal dip to 0%, reading as "margin collapsed" when there was
    // simply no activity that month.
    $values = collect($points)->pluck('value')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
    $min = $values->min() ?? 0;
    $max = $values->max() ?? 0;
    $span = max(1, $max - $min);
    $w = 300; $h = 96; $left = 4; $right = 4; $top = 16; $bottom = 12;
    $step = count($points) > 1 ? ($w - $left - $right) / (count($points) - 1) : 0;
    $coords = [];
    foreach ($points as $i => $point) {
        $x = $left + $i * $step;
        $y = $point['value'] !== null ? $top + ($h - $top - $bottom) * (1 - (((float) $point['value'] - $min) / $span)) : null;
        $coords[] = [$x, $y, $point];
    }
    // Polyline broken into separate segments at each null — a gap month
    // ends the current segment instead of drawing a connecting line through
    // a fabricated position for it.
    $segments = [];
    $current = [];
    foreach ($coords as [$x, $y, $point]) {
        if ($y === null) {
            if (! empty($current)) { $segments[] = $current; $current = []; }
            continue;
        }
        $current[] = [$x, $y];
    }
    if (! empty($current)) { $segments[] = $current; }
    $isMoney = $max >= 1000;
    $fmt = fn ($v) => $isMoney ? (abs($v) >= 1000000 ? number_format($v / 1000000, 1).'M' : (abs($v) >= 1000 ? number_format($v / 1000).'K' : number_format($v))) : number_format($v, $max < 10 ? 1 : 0);
@endphp
<div class="chart-card" style="--chartColor:{{ $color }}">
    <div class="chart-title">{{ $title }}</div>
    <div class="chart-sub">Rolling last 12 months</div>
    <div class="linebox">
        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none">
            @foreach ([0.25, 0.5, 0.75] as $frac)
                <line x1="0" y1="{{ $top + ($h - $top - $bottom) * $frac }}" x2="{{ $w }}" y2="{{ $top + ($h - $top - $bottom) * $frac }}" stroke="#edf2f7" stroke-width="1" />
            @endforeach
            @foreach ($segments as $segment)
                <polyline fill="none" stroke="{{ $color }}" stroke-width="2" points="{{ collect($segment)->map(fn ($c) => $c[0].','.$c[1])->implode(' ') }}" />
            @endforeach
            @foreach ($coords as $i => [$x, $y, $point])
                @if ($y !== null)
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="2.4" fill="{{ $color }}" />
                    @if ($i % 2 === 0 || $i === count($coords) - 1)
                        <text x="{{ $x }}" y="{{ max(9, $y - 6) }}" font-size="7.5" font-weight="700" fill="{{ $color }}" text-anchor="middle">{{ $fmt((float) $point['value']) }}</text>
                    @endif
                @endif
            @endforeach
        </svg>
    </div>
    <div class="month-labels">
        @foreach ($points as $i => $point)
            <span class="{{ $i === count($points) - 1 ? 'current' : '' }}" style="{{ $i === count($points) - 1 ? 'font-weight:800;color:'.$color : '' }}">{{ $point['label'] }}{{ $i === count($points) - 1 ? '*' : '' }}</span>
        @endforeach
    </div>
</div>
