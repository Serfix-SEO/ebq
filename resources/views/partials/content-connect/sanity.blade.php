<div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Get your Sanity token and project ID') }}</p>
    <ol class="mt-2 list-decimal space-y-1 ps-4 text-xs text-slate-600 dark:text-slate-300">
        <li>{{ __('Go to sanity.io/manage, open your project, then API → Tokens → Add API token.') }}</li>
        <li>{{ __('Give the token Editor permissions and paste it below. The project ID is shown on the same page.') }}</li>
    </ol>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Articles are created as documents with the standard blog fields (title, slug, excerpt, mainImage, body as Portable Text, publishedAt). Because Sanity is headless we can\'t know your public article URLs — set the optional pattern below to enable Google indexing and rank tracking.') }}</p>
</div>
<div class="mt-3 grid gap-3 sm:grid-cols-3">
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Project ID') }}</label>
        <input wire:model="sanityProjectId" type="text" placeholder="abc123de" autocomplete="off"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('sanityProjectId') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('API token') }}</label>
        <input wire:model="sanityToken" type="password" autocomplete="new-password"
            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
        @error('sanityToken') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    </div>
    @include('partials.content-connect.post-status')
</div>
<div class="mt-3">
    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Public URL pattern (optional)') }}</label>
    <input wire:model="sanityUrlPattern" type="text" placeholder="https://your-site.com/blog/{slug}" autocomplete="off"
        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Where your frontend serves each article — {slug} is replaced with the article link.') }}</p>
    @error('sanityUrlPattern') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
</div>
