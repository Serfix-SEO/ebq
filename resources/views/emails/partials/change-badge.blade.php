@php
    $suffix = $suffix ?? '%';
    $dir = $metric['direction'];
    $pct = $metric['change_percent'];
    $isPos = $metric['is_positive'];

    if ($dir === 'flat') {
        $cls = 'change-flat';
        $arrow = '→';
        $text = __('No change');
    } elseif ($dir === 'up') {
        $cls = $isPos ? 'change-up-good' : 'change-up-bad';
        $arrow = '↑';
        $text = $pct !== null ? "+{$pct}{$suffix}" : __('New');
    } else {
        $cls = $isPos ? 'change-down-good' : 'change-down-bad';
        $arrow = '↓';
        $text = $pct !== null ? "{$pct}{$suffix}" : '—';
    }
@endphp
<span class="kpi-change {{ $cls }}">{{ $arrow }} {{ $text }}</span>
