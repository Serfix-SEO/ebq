<div>
    @if ($show)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
            @if ($added)
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 flex-none text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <div>
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $status }}</p>
                        <a href="{{ route('sitemaps.index') }}" class="mt-1 inline-block text-xs font-medium text-amber-700 underline dark:text-amber-300">{{ __('Manage sitemaps') }} →</a>
                    </div>
                </div>
            @else
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ __('Add a sitemap to start seeing your data') }}</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            {{ __('This site isn’t connected to Google Search Console and has no sitemap yet, so there’s nothing to analyse. Add your sitemap URL and we’ll crawl your pages to populate Site Health, pages and SEO issues.') }}
                        </p>
                        <form wire:submit="addSitemap" class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <input type="url" wire:model="newSitemapUrl" placeholder="https://example.com/sitemap.xml"
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-900 sm:max-w-md" />
                            <button type="submit" wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500 disabled:opacity-50">
                                <span wire:loading.remove wire:target="addSitemap">{{ __('Add sitemap') }}</span>
                                <span wire:loading wire:target="addSitemap">{{ __('Adding…') }}</span>
                            </button>
                        </form>
                        @error('newSitemapUrl')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Prefer Google data?') }} <a href="{{ route('settings.index') }}" class="font-medium underline">{{ __('Connect Search Console') }}</a> {{ __('instead.') }}
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
