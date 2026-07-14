@php
    $rows = array_values($history ?? []);
    $n = count($rows);
    $maxActive = 1;
    foreach ($rows as $rr) { $maxActive = max($maxActive, (int) ($rr['active'] ?? 0)); }
    $maxLost = 1;
    foreach ($rows as $rr) { $maxLost = max($maxLost, (int) ($rr['lost'] ?? 0)); }
    $w = 620; $h = 150; $base = 110; $gap = 6;
    $bw = $n > 0 ? max(6, ($w - ($n - 1) * $gap) / $n) : 0;
@endphp
<svg viewBox="0 0 {{ $w }} {{ $h }}" width="100%" role="img" aria-label="Monthly active and lost backlinks">
    <line x1="0" y1="{{ $base }}" x2="{{ $w }}" y2="{{ $base }}" stroke="#e2e8f0" stroke-width="1"/>
    @foreach ($rows as $i => $rr)
        @php
            $x = $i * ($bw + $gap);
            $ah = round(($maxActive ? ((int) ($rr['active'] ?? 0) / $maxActive) : 0) * 90, 1);
            $lh = round(($maxLost ? ((int) ($rr['lost'] ?? 0) / $maxLost) : 0) * 22, 1);
        @endphp
        <rect x="{{ round($x, 1) }}" y="{{ round($base - $ah, 1) }}" width="{{ round($bw, 1) }}" height="{{ $ah }}" rx="2" fill="#F26419"/>
        <rect x="{{ round($x, 1) }}" y="{{ $base }}" width="{{ round($bw, 1) }}" height="{{ $lh }}" rx="2" fill="#e34948"/>
    @endforeach
    @if ($n > 0)
        <text x="0" y="{{ $h - 8 }}" font-size="11" fill="#94a3b8" font-family="DejaVu Sans, sans-serif">{{ $rows[0]['month'] ?? '' }}</text>
        <text x="{{ $w }}" y="{{ $h - 8 }}" font-size="11" fill="#94a3b8" text-anchor="end" font-family="DejaVu Sans, sans-serif">{{ $rows[$n-1]['month'] ?? '' }}</text>
    @endif
</svg>
