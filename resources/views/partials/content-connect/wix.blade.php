<div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Get your Wix API key and site ID') }}</p>
    <ol class="mt-2 list-decimal space-y-1 ps-4 text-xs text-slate-600 dark:text-slate-300">
        <li>{{ __('Go to wix.com/my-account → API Keys and create a key with Blog, Media and Site Members permissions (or All site permissions).') }}</li>
        <li>{{ __('Your site ID is the long code in your dashboard URL: wix.com/dashboard/{site-id}/…') }}</li>
        <li>{{ __('Your site needs the Wix Blog app installed (most templates include it).') }}</li>
    </ol>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Articles are converted to Wix\'s native editor format, and images are copied into your Media Manager.') }}</p>
</div>
<div class="mt-3 grid gap-3 sm:grid-cols-3">
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('API key') }}</label>
        <input wire:model="wixApiKey" type="password" autocomplete="new-password"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('wixApiKey') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Site ID') }}</label>
        <input wire:model="wixSiteId" type="text" placeholder="12345678-abcd-…" autocomplete="off"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('wixSiteId') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    @include('partials.content-connect.post-status')
</div>
