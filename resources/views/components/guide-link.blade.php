@props([
    'anchor' => '',
    'label' => 'Guide',
    'title' => 'Open the matching section of the product guide in a new tab',
])

@php
    $href = route('guide').($anchor ? '#'.$anchor : '');
@endphp

<a href="{{ $href }}"
   target="_blank"
   rel="noopener noreferrer"
   title="{{ $title }}"
   {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-medium text-slate-600 shadow-sm transition hover:border-orange-300 hover:text-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500/40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-orange-500/40 dark:hover:text-orange-400']) }}>
    <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
    </svg>
    {{ $label }}
    <svg class="h-2.5 w-2.5 opacity-60" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
    </svg>
</a>
