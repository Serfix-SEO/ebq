@php
    $dir = $metric['direction'];
    $pct = $metric['change_percent'];
    $isPos = $metric['is_positive'];

    if ($dir === 'flat') {
        $color = '#94a3b8';
        $text = '—';
    } elseif ($dir === 'up') {
        $color = $isPos ? '#16a34a' : '#dc2626';
        $text = $pct !== null ? "↑ +{$pct}%" : '↑ '.__('New');
    } else {
        $color = $isPos ? '#16a34a' : '#dc2626';
        $text = $pct !== null ? "↓ {$pct}%" : '↓';
    }
@endphp
<span style="color:{{ $color }};font-weight:600;font-size:12px;white-space:nowrap">{{ $text }}</span>
