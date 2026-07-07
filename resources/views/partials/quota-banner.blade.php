{{-- Per-plan API quota banner. Rendered from `session('quota_notice')`,
     which the QuotaExceededException render handler flashes on
     non-JSON requests. Provider + message + upgrade CTA so the user
     can act without leaving the screen. --}}
@php
    $quotaNotice = session('quota_notice');
@endphp
@if (is_array($quotaNotice) && ($quotaNotice['error'] ?? '') === 'quota_exceeded')
    <div
        x-data="{ open: true }"
        x-show="open"
        class="mb-4 flex items-start justify-between gap-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/40 dark:bg-amber-900/20 dark:text-amber-100"
        role="status"
    >
        <div class="flex-1">
            <div class="font-semibold">{{ __('Plan limit reached') }}</div>
            <div class="mt-1">{{ $quotaNotice['message'] ?? __('You have reached your plan limit.') }}</div>
        </div>
        <div class="flex items-center gap-2">
            <a
                href="{{ $quotaNotice['upgrade_url'] ?? url('/billing') }}"
                class="rounded-md bg-amber-600 px-3 py-1.5 font-medium text-white hover:bg-amber-700"
            >{{ __('Upgrade plan') }}</a>
            <button
                type="button"
                x-on:click="open = false"
                class="rounded-md p-1 text-amber-700 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-900/40"
                aria-label="{{ __('Dismiss') }}"
            >&times;</button>
        </div>
    </div>
@endif
