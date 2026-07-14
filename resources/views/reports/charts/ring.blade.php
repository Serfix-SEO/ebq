@php
    $value = max(0, min(100, (float) ($value ?? 0)));
    $display = $display ?? (int) round($value);
    $label = $label ?? '';
    $color = $color ?? '#F26419';
    $cx = 40; $cy = 40; $r = 32;
    $f = $value / 100;
    $theta = $f * 2 * M_PI;
    $ex = $cx + $r * sin($theta);
    $ey = $cy - $r * cos($theta);
    $large = $f > 0.5 ? 1 : 0;
    $arc = $f <= 0 ? '' : ($f >= 1
        ? "M {$cx} " . ($cy - $r) . " A {$r} {$r} 0 1 1 " . ($cx - 0.01) . " " . ($cy - $r)
        : "M {$cx} " . ($cy - $r) . " A {$r} {$r} 0 {$large} 1 " . round($ex, 2) . " " . round($ey, 2));
@endphp
<svg viewBox="0 0 80 80" width="{{ $size ?? 72 }}" height="{{ $size ?? 72 }}" role="img" aria-label="{{ $label }} {{ $display }}">
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="none" stroke="#e2e8f0" stroke-width="8"/>
    @if ($arc !== '')
        <path d="{{ $arc }}" fill="none" stroke="{{ $color }}" stroke-width="8" stroke-linecap="round"/>
    @endif
    <text x="40" y="46" text-anchor="middle" font-size="19" font-weight="500" fill="#1e293b" font-family="DejaVu Sans, sans-serif">{{ $display }}</text>
</svg>
