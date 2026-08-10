<div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Get your Shopify access token') }}</p>
    <ol class="mt-2 list-decimal space-y-1 ps-4 text-xs text-slate-600 dark:text-slate-300">
        <li>{{ __('In your Shopify admin, open Settings → Apps and sales channels → Develop apps.') }}</li>
        <li>{{ __('Create an app (name it "Serfix"), open Configuration → Admin API scopes, and enable read_content and write_content.') }}</li>
        <li>{{ __('Install the app, then copy the Admin API access token (it starts with shpat_). It is shown only once.') }}</li>
        <li>{{ __('Your permanent store domain ends in .myshopify.com — find it under Settings → Domains.') }}</li>
    </ol>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('After connecting you pick which blog articles publish to. Articles go to your Online Store blog with title, body, tags and featured image.') }}</p>
</div>
<div class="mt-3 grid gap-3 sm:grid-cols-3">
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Store domain') }}</label>
        <input wire:model="shopifyStoreDomain" type="text" placeholder="your-store.myshopify.com" autocomplete="off"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('shopifyStoreDomain') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Admin API access token') }}</label>
        <input wire:model="shopifyToken" type="password" placeholder="shpat_…" autocomplete="new-password"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('shopifyToken') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    @include('partials.content-connect.post-status')
</div>
