@props(['website', 'active' => null])
@php
    // Same nav on every page that has a website identified (dashboard,
    // statistics, site-explorer, the /overview hub) — clicking any tab
    // always lands on the canonical hub so the whole flow is reachable from
    // wherever a client happens to enter the app. Status pills come from
    // WebsiteTabStatus, the single source of truth also used by the hub
    // controller, so this bar can never disagree with the page it's on.
    $status = app(\App\Services\WebsiteTabStatus::class)->forWebsite($website);
    $labels = [
        'explorer' => __('Site Explorer'),
        'health' => __('Site Health'),
        'statistics' => __('Statistics'),
    ];
    $pillClasses = [
        'processing' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
        'needs_action' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
    ];
@endphp
<nav {{ $attributes->merge(['class' => 'flex flex-wrap gap-1.5 border-b border-slate-200 pb-px dark:border-slate-800']) }} role="tablist">
    @foreach ($labels as $key => $label)
        @php
            $isActive = $active === $key;
            $s = $status[$key];
        @endphp
        {{-- Preserve ?country=... across tab navigation — InsightCards reads
             country straight from the URL (#[Url]), so dropping it here would
             silently reset the filter and make numbers look wrong/inconsistent
             between pages (not a caching issue, but reads exactly like one). --}}
        <a href="{{ route('website-overview', array_filter(['tab' => $key, 'country' => request('country')])) }}"
            role="tab" aria-selected="{{ $isActive ? 'true' : 'false' }}"
            @class([
                'group inline-flex items-center gap-2 rounded-t-lg border border-b-0 px-3.5 py-2.5 text-sm font-semibold transition',
                'border-slate-200 bg-white text-slate-900 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100' => $isActive,
                'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-900/60 dark:hover:text-slate-200' => ! $isActive,
            ])>
            {{ $label }}
            @if ($s['state'] === 'processing')
                <span class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $pillClasses['processing'] }}">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500"></span>
                    {{ __('Processing') }}
                </span>
            @elseif ($s['state'] === 'needs_action')
                <span class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $pillClasses['needs_action'] }}">
                    {{ __('Needs action') }}
                </span>
            @endif
        </a>
    @endforeach
</nav>
