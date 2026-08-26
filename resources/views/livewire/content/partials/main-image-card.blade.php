{{-- Main image (WP featured image) — ALWAYS visible when one exists, whatever
     the embed toggle says (cocomii 2026-08-26: clients couldn't preview it).
     Three states: embedded in body / kept out by settings / removed by the
     client in the editor (it still ships as the thumbnail — say so). --}}
@if (! empty($featuredImage))
    <div class="mb-4 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
         x-data="{ regenerating: false }">
        <img src="{{ $featuredImage->url() }}" alt="{{ $featuredImage->alt_text }}" class="h-48 w-full object-cover" :class="regenerating ? 'opacity-40' : ''" />
        <div class="flex items-start gap-2 px-4 py-3">
            <svg class="mt-0.5 h-4 w-4 flex-none text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M18 9h.008v.008H18V9zm.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/><rect x="2.25" y="4.5" width="19.5" height="15" rx="2.25"/></svg>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ __('Main image') }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    @if ($topic?->plan !== null && ! $topic->plan->toggle('featured_image'))
                        {{ __('Used as your post\'s featured image in WordPress. It is not shown at the top of the article body because you turned that off in settings.') }}
                    @elseif ($featuredEmbedded ?? false)
                        {{ __('Used as your post\'s featured image and shown at the top of your article.') }}
                    @else
                        {{ __('Used as your post\'s featured image. You removed it from the article body — it will still be your post\'s thumbnail.') }}
                    @endif
                </p>
            </div>
            @if (trim((string) $featuredImage->prompt) !== '')
                <button type="button"
                    x-on:click="regenerating = true; $wire.regenerateImage('{{ $featuredImage->id }}').then(id => { if (! id) { regenerating = false; return; } const poll = setInterval(async () => { const r = await $wire.pollInlineImage(id); if (r && (r.url || r.failed)) { clearInterval(poll); regenerating = false; $wire.$refresh(); } }, 2500); setTimeout(() => { clearInterval(poll); regenerating = false; }, 90000); })"
                    :disabled="regenerating"
                    class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    <span x-show="! regenerating">{{ __('Generate a new image') }}</span>
                    <span x-show="regenerating" x-cloak>{{ __('Generating…') }}</span>
                </button>
            @endif
        </div>
    </div>
@endif
