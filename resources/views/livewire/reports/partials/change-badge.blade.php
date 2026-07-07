@php
    $dir = $metric['direction'];
    $pct = $metric['change_percent'];
    $isPos = $metric['is_positive'];

    $color = match (true) {
        $dir === 'flat' => 'text-slate-400',
        $isPos => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-red-600 dark:text-red-400',
    };

    $arrow = match ($dir) {
        'up' => '↑',
        'down' => '↓',
        default => '→',
    };

    if ($dir === 'flat') {
        $text = '—';
    } elseif ($pct !== null) {
        $text = $arrow . ' ' . ($dir === 'up' ? '+' : '') . $pct . '%';
    } else {
        $text = $arrow . ' ' . __('New');
    }
@endphp
<span class="whitespace-nowrap text-xs font-semibold {{ $color }}">{{ $text }}</span>
