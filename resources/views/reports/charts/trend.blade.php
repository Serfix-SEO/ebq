{{-- Referring-domains trend: SVG area chart over the monthly history rows.
     Pure inline SVG (no JS) with native <title> hover tooltips per point —
     safe on the public share page; the PDF keeps its own dompdf-safe charts.
     Params: $history (payload rows), $accent. --}}
@php
    $monthLabel = function ($v): string {
        try {
            return \Illuminate\Support\Carbon::parse((string) $v)->format('M Y');
        } catch (\Throwable) {
            return (string) $v;
        }
    };
    $rows = collect($history ?? [])->filter(fn ($h) => isset($h['referring_domains']))->values();
    $vals = $rows->pluck('referring_domains')->map(fn ($v) => (int) $v);
@endphp
@if ($rows->count() >= 2)
    @php
        $w = 560; $h = 150; $pad = 8;
        $max = max(1, $vals->max());
        $min = min($vals->min(), $max - 1);
        $range = max(1, $max - $min);
        $step = ($w - 2 * $pad) / max(1, $rows->count() - 1);
        $pts = $vals->map(fn ($v, $i) => [
            round($pad + $i * $step, 1),
            round($h - $pad - (($v - $min) / $range) * ($h - 2 * $pad - 14), 1),
        ]);
        $line = $pts->map(fn ($p) => $p[0].','.$p[1])->implode(' ');
        $area = "M {$pad},".($h - $pad).' L '.$line.' L '.round($pad + ($rows->count() - 1) * $step, 1).','.($h - $pad).' Z';
        $last = $pts->last();
        $first = $rows->first(); $latest = $rows->last();
    @endphp
    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="block h-40 w-full" aria-label="Referring domains over time">
        <defs>
            <linearGradient id="rd-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="{{ $accent }}" stop-opacity="0.28" />
                <stop offset="100%" stop-color="{{ $accent }}" stop-opacity="0.02" />
            </linearGradient>
        </defs>
        {{-- horizontal gridlines --}}
        @foreach ([0.25, 0.5, 0.75] as $g)
            <line x1="{{ $pad }}" x2="{{ $w - $pad }}" y1="{{ round($pad + $g * ($h - 2 * $pad), 1) }}" y2="{{ round($pad + $g * ($h - 2 * $pad), 1) }}" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3 4" />
        @endforeach
        <path d="{{ $area }}" fill="url(#rd-fill)" />
        <polyline points="{{ $line }}" fill="none" stroke="{{ $accent }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
        {{-- hover points: invisible-until-hover markers with native tooltips --}}
        @foreach ($pts as $i => $pt)
            <g class="opacity-0 transition-opacity hover:opacity-100">
                <title>{{ $monthLabel($rows[$i]['month'] ?? '') }} — {{ number_format((int) $rows[$i]['referring_domains']) }} referring domains</title>
                <rect x="{{ round($pt[0] - $step / 2, 1) }}" y="0" width="{{ round($step, 1) }}" height="{{ $h }}" fill="transparent" />
                <line x1="{{ $pt[0] }}" x2="{{ $pt[0] }}" y1="{{ $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1" stroke-dasharray="2 3" />
                <circle cx="{{ $pt[0] }}" cy="{{ $pt[1] }}" r="4.5" fill="{{ $accent }}" stroke="#fff" stroke-width="2" />
            </g>
        @endforeach
        <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="4" fill="{{ $accent }}" stroke="#fff" stroke-width="2" />
    </svg>
    <div class="mt-1 flex items-baseline justify-between text-[11px] text-slate-400">
        <span>{{ $monthLabel($first['month'] ?? '') }}</span>
        <span class="font-semibold text-slate-600">{{ number_format((int) ($latest['referring_domains'] ?? 0)) }} referring domains</span>
        <span>{{ $monthLabel($latest['month'] ?? '') }}</span>
    </div>
@endif
