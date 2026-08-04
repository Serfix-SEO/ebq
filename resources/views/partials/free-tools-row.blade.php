{{--
    The free-tool chips, in ONE place.

    They used to exist only on the home page, so a visitor who landed on a tool
    page from search saw at most a single "Also free: …" pill and had no idea
    the other three existed (2026-08-02). Every tool page now renders this row,
    and the home page renders the same partial — one definition, so the set can
    never drift between pages again.

    @param string|null $except  Route name of the page doing the including, so a
                                tool never links to itself.
    @param bool $label          Show the "Free tools:" prefix (home page style).
--}}
@php
    $freeTools = [
        [
            'route' => 'tools.pagespeed',
            'label' => __('PageSpeed Test'),
            'icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
        ],
        [
            'route' => 'tools.audit',
            'label' => __('SEO Audit'),
            'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
        ],
        [
            'route' => 'tools.rank-tracker',
            'label' => __('Rank Checker'),
            'icon' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
        ],
        [
            'route' => 'tools.keyword-volume',
            'label' => __('Volume Checker'),
            'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        ],
    ];
    $except = $except ?? null;
    $label = $label ?? false;
@endphp

<div class="flex flex-wrap items-center justify-center gap-2">
    @if ($label)
        <span class="text-xs font-semibold text-slate-500">{{ __('Free tools:') }}</span>
    @endif
    @foreach ($freeTools as $tool)
        @continue($tool['route'] === $except)
        <a href="{{ route($tool['route']) }}"
           class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm transition hover:-translate-y-0.5 hover:border-orange-400 hover:bg-orange-100 hover:shadow-md active:translate-y-0">
            <svg class="h-3.5 w-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $tool['icon'] }}" /></svg>
            {{ $tool['label'] }}
            <svg class="h-3 w-3 text-orange-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
        </a>
    @endforeach
</div>
