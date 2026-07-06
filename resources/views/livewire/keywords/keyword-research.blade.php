@php
    $tabs = ['ideas' => 'Ideas', 'volume' => 'Volume', 'gap' => 'Competitor Gap'];
@endphp

<div>
    <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-700">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition',
                    'border-orange-600 text-orange-600 dark:text-orange-400' => $tab === $key,
                    'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' => $tab !== $key,
                ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-6">
        @if ($tab === 'ideas')
            <livewire:keywords.keyword-idea-finder :preset="$preset" :key="'kr-ideas-'.$nonce" />
        @elseif ($tab === 'volume')
            <livewire:keywords.keyword-volume-finder :preset="$preset" :key="'kr-volume-'.$nonce" />
        @else
            <div class="mb-4 flex justify-end">
                <a href="{{ route('competitive.competitors') }}"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200">Find competitors →</a>
            </div>
            <livewire:competitive.keyword-gap-analysis :key="'kr-gap-'.$nonce" />
        @endif
    </div>
</div>
