@php
    $wid = session('current_website_id');
    // ANY connected destination counts — WordPress, Laravel, or a custom
    // webhook (all stored as PLATFORM_WEBHOOK, distinguished only by
    // config.flavor). The banner's job is "nothing publishes yet", not
    // "you specifically lack WordPress" (2026-07-22 — multiple integration
    // options now exist, so it must not name one, and must clear for any of
    // them once connected).
    $hasIntegration = $wid && \App\Models\ContentIntegration::query()
        ->where('website_id', $wid)
        ->where('status', \App\Models\ContentIntegration::STATUS_CONNECTED)
        ->exists();
@endphp

@if (! $hasIntegration && \Illuminate\Support\Facades\Route::has('content.integrations'))
    <div class="flex flex-col gap-3 rounded-2xl border border-orange-200 bg-gradient-to-r from-orange-50 to-white p-4 sm:flex-row sm:items-center dark:border-orange-900 dark:from-orange-950 dark:to-slate-900">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-slate-800">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Connect a destination to publish') }}</p>
            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ __('Link WordPress, Laravel, or a custom webhook to publish your articles automatically — nothing goes live until it\'s connected.') }}</p>
        </div>
        <a href="{{ route('content.integrations') }}"
           class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
            {{ __('Connect a destination') }}
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </a>
    </div>
@endif
