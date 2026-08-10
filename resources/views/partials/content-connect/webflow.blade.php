<div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Get your Webflow site token') }}</p>
    <ol class="mt-2 list-decimal space-y-1 ps-4 text-xs text-slate-600 dark:text-slate-300">
        <li>{{ __('In Webflow, open your site\'s Settings → Apps & integrations → API access.') }}</li>
        <li>{{ __('Generate an API token with CMS read and write plus Sites read permissions, and paste it below.') }}</li>
    </ol>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('After connecting you pick the site and the CMS collection (it needs a Rich text field for the article body — a standard Blog Posts collection works). For articles to have public pages, the collection needs a template page in your Webflow design.') }}</p>
</div>
<div class="mt-3 grid gap-3 sm:grid-cols-2">
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('API token') }}</label>
        <input wire:model="webflowToken" type="password" autocomplete="new-password"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('webflowToken') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    @include('partials.content-connect.post-status')
</div>
