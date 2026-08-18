{{--
    "Take it elsewhere" — copy or download the article body.

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

<div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800"
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
    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Take it elsewhere') }}</p>

    <div class="grid grid-cols-2 gap-2">
        <button type="button" @click="grab(@js($exportHtml), 'html')"
                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
            <span x-show="copied !== 'html'">{{ __('Copy HTML') }}</span>
            <span x-show="copied === 'html'" x-cloak class="text-success">{{ __('Copied') }}</span>
        </button>
        <button type="button" @click="grab(@js($exportMd), 'md')"
                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
            <span x-show="copied !== 'md'">{{ __('Copy Markdown') }}</span>
            <span x-show="copied === 'md'" x-cloak class="text-success">{{ __('Copied') }}</span>
        </button>
    </div>

    <div class="mt-2 flex items-center justify-center gap-3 text-xs text-slate-500 dark:text-slate-400">
        <a href="{{ $exportHtml }}?download=1" class="hover:text-orange-600 hover:underline">{{ __('Download .html') }}</a>
        <span class="text-slate-300 dark:text-slate-700">·</span>
        <a href="{{ $exportMd }}?download=1" class="hover:text-orange-600 hover:underline">{{ __('Download .md') }}</a>
    </div>

    <p class="mt-2 text-center text-xs text-slate-400">
        {{ __('For websites we cannot post to automatically — paste this into your site editor.') }}
    </p>
</div>
