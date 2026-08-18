{{--
    "Take it elsewhere" — copy or download the article body.

    Sits directly under the page header, above the article, because it was
    missed at the bottom of the sidebar (owner feedback 2026-08-16). Full-width
    bar rather than header-row buttons: on a phone the title row is already
    tight, and a dropdown would hide the very thing we are trying to surface.

    For clients whose site has no content API (Hostinger Horizon and other site
    builders, hand-built sites), no publish driver can ever work; pasting is the
    only route. Before this existed the only option was selecting the rendered
    preview in the browser, which loses headings, links and image references.

    Both Copy buttons fetch the export route rather than embedding the article
    in the DOM: the body can be tens of kB, and Livewire would then diff it on
    every re-render. Download links hit the same route with ?download=1.
--}}
@php
    $exportHtml = route('content.article.export', ['topic' => $topic->id, 'format' => 'html']);
    $exportMd = route('content.article.export', ['topic' => $topic->id, 'format' => 'md']);
@endphp

<div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/60"
     x-data="{
        copied: null,
        async grab(url, tag) {
            try {
                const res = await fetch(url, { headers: { 'Accept': 'text/plain' } });
                if (! res.ok) throw new Error(res.status);
                await navigator.clipboard.writeText(await res.text());
                this.copied = tag;
                setTimeout(() => { this.copied = null }, 2000);
            } catch (e) {
                // Clipboard access is refused on insecure origins and by some
                // browsers without a user gesture — send them to the file.
                window.open(url + '?download=1', '_blank');
            }
        }
     }">
    {{-- gap-y-2, not gap-y-3: the prebuilt Tailwind bundle has no gap-y-3, and
         a class that isn't compiled silently renders nothing — the buttons would
         have sat flush against the label once they wrapped on a phone. --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ __('Take it elsewhere') }}</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ __('For websites we cannot post to automatically — paste this into your site editor.') }}
            </p>
        </div>

        <div class="flex flex-none flex-wrap items-center gap-2">
            <button type="button" @click="grab(@js($exportHtml), 'html')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-orange-400 hover:text-orange-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:text-orange-400">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2v-2m-6-12h6a2 2 0 012 2v6m-8-8V3.5L18.5 9H16"/></svg>
                <span x-show="copied !== 'html'">{{ __('Copy HTML') }}</span>
                <span x-show="copied === 'html'" x-cloak class="text-success">{{ __('Copied') }}</span>
            </button>

            <button type="button" @click="grab(@js($exportMd), 'md')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-orange-400 hover:text-orange-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:text-orange-400">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2v-2m-6-12h6a2 2 0 012 2v6m-8-8V3.5L18.5 9H16"/></svg>
                <span x-show="copied !== 'md'">{{ __('Copy Markdown') }}</span>
                <span x-show="copied === 'md'" x-cloak class="text-success">{{ __('Copied') }}</span>
            </button>

            <span class="text-xs text-slate-400 dark:text-slate-600">|</span>

            <a href="{{ $exportHtml }}?download=1" class="text-xs font-medium text-slate-500 hover:text-orange-600 hover:underline dark:text-slate-400">{{ __('Download .html') }}</a>
            <a href="{{ $exportMd }}?download=1" class="text-xs font-medium text-slate-500 hover:text-orange-600 hover:underline dark:text-slate-400">{{ __('Download .md') }}</a>
        </div>
    </div>
</div>
