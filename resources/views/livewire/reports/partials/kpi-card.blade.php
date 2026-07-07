@php
    $format = $format ?? 'number';
    $changeSuffix = $changeSuffix ?? '%';
    $dir = $metric['direction'];
    $pct = $metric['change_percent'];
    $isPos = $metric['is_positive'];

    $formattedValue = match ($format) {
        'percent' => $metric['current'] . '%',
        'decimal' => $metric['current'],
        default => number_format($metric['current']),
    };

    $formattedPrev = match ($format) {
        'percent' => $metric['previous'] . '%',
        'decimal' => $metric['previous'],
        default => number_format($metric['previous']),
    };

    $changeColor = match (true) {
        $dir === 'flat' => 'text-slate-400',
        $isPos => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-red-600 dark:text-red-400',
    };

    $changeBg = match (true) {
        $dir === 'flat' => 'bg-slate-50 dark:bg-slate-800',
        $isPos => 'bg-emerald-50 dark:bg-emerald-500/10',
        default => 'bg-red-50 dark:bg-red-500/10',
    };

    $arrow = match ($dir) {
        'up' => '↑',
        'down' => '↓',
        default => '→',
    };

    if ($dir === 'flat') {
        $changeText = __('No change');
    } elseif ($pct !== null) {
        $changeText = ($dir === 'up' ? '+' : '') . $pct . $changeSuffix;
    } else {
        $changeText = __('New');
    }
@endphp

<div class="min-w-0 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
    <p class="truncate text-[10px] font-medium uppercase tracking-wider text-slate-400">{{ $label }}</p>
    <p class="mt-0.5 truncate text-base font-bold tabular-nums leading-tight text-slate-900 dark:text-slate-100">{{ $formattedValue }}</p>
    <span class="mt-0.5 inline-flex items-center gap-0.5 rounded-full px-1.5 py-px text-[10px] font-semibold {{ $changeBg }} {{ $changeColor }}">{{ $arrow }} {{ $changeText }}</span>
    <p class="mt-0.5 text-[10px] text-slate-400">{{ __('was') }} {{ $formattedPrev }}</p>
</div>
