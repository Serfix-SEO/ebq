{{-- "Listen" — the browser reads the article aloud (Web Speech API).

     A review aid: a client can proofread by ear before approving. Nothing is
     generated or stored, nothing is sent anywhere, and nothing reaches their
     website — the speaking is done by their own device.

     Secondary to Edit on purpose: outlined, so the primary action beside it
     stays the obvious one. Behaviour lives in resources/js/article-speech.js;
     x-cloak keeps the whole control hidden until Alpine has decided whether
     the browser supports speech at all. --}}
<div x-data="articleSpeech" x-cloak x-show="supported"
     data-lang="{{ $speechLanguage ?? 'en' }}"
     class="flex items-center gap-1.5">

    {{-- Idle --}}
    <button type="button" x-show="! speaking && ! paused" x-on:click="listen()"
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z"/></svg>
        {{ __('Listen') }}
    </button>
    {{-- While it reads, the page auto-scrolls to follow along — which carried
         these controls off screen, so there was no way to pause without
         scrolling back up (owner 2026-09-26). The playing controls therefore
         sit in a bar pinned to the bottom of the viewport, the way a media
         player behaves. It lives inside this component so it shares state; no
         ancestor uses transform/filter, so `fixed` really is viewport-fixed.
         pointer-events-none on the strip keeps the rest of the page clickable
         either side of the bar. --}}
    <div x-show="speaking || paused" x-cloak
         class="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <div class="pointer-events-auto flex items-center gap-2 rounded-full border border-slate-200 bg-white/95 px-3 py-2 shadow-xl shadow-slate-900/10 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
            <button type="button" x-show="speaking" x-on:click="pause()"
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-full bg-orange-600 px-4 py-2 text-sm font-bold text-white hover:bg-orange-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/></svg>
                {{ __('Pause') }}
            </button>

            <button type="button" x-show="paused" x-on:click="resume()"
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-full bg-orange-600 px-4 py-2 text-sm font-bold text-white hover:bg-orange-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/></svg>
                {{ __('Resume') }}
            </button>

            <button type="button" x-on:click="stop()" aria-label="{{ __('Stop reading') }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-full border border-slate-300 p-2 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 017.5 5.25h9a2.25 2.25 0 012.25 2.25v9a2.25 2.25 0 01-2.25 2.25h-9a2.25 2.25 0 01-2.25-2.25v-9z"/></svg>
            </button>

            <select x-on:change="setRate($event.target.value)" aria-label="{{ __('Reading speed') }}"
                    class="rounded-full border border-slate-300 bg-white py-2 ps-2 pe-6 text-xs font-semibold text-slate-700 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                @foreach (['0.75', '1', '1.25', '1.5', '2'] as $speed)
                    <option value="{{ $speed }}" @selected($speed === '1')>{{ $speed }}&times;</option>
                @endforeach
            </select>

            <span class="whitespace-nowrap pe-1 ps-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400"
                  x-text="`${index + 1} / ${total}`"></span>
        </div>
    </div>
</div>
