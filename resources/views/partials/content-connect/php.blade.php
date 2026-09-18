{{--
    PHP / HTML website connect card.

    For site owners who are not developers: a hand-built PHP site, a static
    HTML site on PHP hosting, a small custom CMS. Underneath it is a webhook
    integration — the kit (resources/snippets/php/, zipped by PhpKitBuilder)
    is the receiver, so they never write one. The download carries this
    site's signing secret already filled in; the customer never sees or
    copies it. Built for safibusinessservice.com, support ticket 2026-09-13.
--}}
<p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">
    {{ __('For websites built without WordPress or a store builder. Download your kit, upload it to your website, and your articles will appear as real pages on your own domain — with the SEO tags search engines read.') }}
</p>

<ol class="mt-4 space-y-4">
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-600 text-xs font-bold text-white">1</span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Download your kit') }}</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('It is made for this website only and already contains its private key. Do not reuse it on another site.') }}</p>
            <button type="button" wire:click="downloadPhpKit" wire:loading.attr="disabled"
                class="mt-2 inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                <span wire:loading.remove wire:target="downloadPhpKit">{{ __('Download kit (.zip)') }}</span>
                <span wire:loading wire:target="downloadPhpKit">{{ __('Preparing…') }}</span>
            </button>
        </div>
    </li>
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-600 text-xs font-bold text-white">2</span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Upload it to your website') }}</p>
            <p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">
                {{ __('Unzip it, then upload the two folders — "serfix" and "articles" — into the main folder of your website: the one that holds your home page (often called public_html, www or htdocs). Your hosting control panel\'s File Manager works fine.') }}
            </p>
        </div>
    </li>
    <li class="flex gap-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-600 text-xs font-bold text-white">3</span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Click "Verify & connect" below') }}</p>
            <p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">
                {{ __('We check that the kit is in place and can save articles. After that, new articles appear at your-site.com/articles/ — add a link to it in your site\'s menu.') }}
            </p>
        </div>
    </li>
</ol>

{{-- The receiver's address. Pre-filled for the recommended install; only
     someone who uploaded into a sub-folder needs to touch it. --}}
<details class="mt-4 rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700" @if ($errors->has('whEndpoint')) open @endif>
    <summary class="cursor-pointer text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('Uploaded somewhere other than your main folder?') }}</summary>
    <label class="mt-3 block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Kit address') }}</label>
    <input wire:model="whEndpoint" type="text" placeholder="{{ $this->suggestedPhpEndpoint() }}"
        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-mono text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
    @error('whEndpoint') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
    <p class="mt-1 text-[11px] text-slate-400">{{ __('The full address of serfix/receiver.php on your website. It must start with https://.') }}</p>
</details>

@include('partials.content-connect.post-status')
