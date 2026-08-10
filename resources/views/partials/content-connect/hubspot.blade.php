<div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Get your HubSpot private app token') }}</p>
    <ol class="mt-2 list-decimal space-y-1 ps-4 text-xs text-slate-600 dark:text-slate-300">
        <li>{{ __('In HubSpot, open Settings → Integrations → Private Apps and create an app (name it "Serfix").') }}</li>
        <li>{{ __('Under Scopes, expand CMS and enable the "content" scope.') }}</li>
        <li>{{ __('Create the app and copy the access token (it starts with pat-).') }}</li>
    </ol>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('After connecting you pick which blog articles publish to. Your account needs at least one blog (Content → Blog).') }}</p>
</div>
<div class="mt-3 grid gap-3 sm:grid-cols-2">
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Private app token') }}</label>
        <input wire:model="hubspotToken" type="password" placeholder="pat-…" autocomplete="new-password"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('hubspotToken') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    @include('partials.content-connect.post-status')
</div>
