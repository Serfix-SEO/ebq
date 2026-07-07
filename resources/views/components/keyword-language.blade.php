@props(['language' => null])

@if (filled($language))
    <span class="ms-1 inline-flex items-center rounded bg-slate-100 px-1 py-px text-[9px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800 dark:text-slate-400"
          title="{{ __('Detected language:') }} {{ strtoupper($language) }}">
        {{ strtoupper($language) }}
    </span>
@endif
