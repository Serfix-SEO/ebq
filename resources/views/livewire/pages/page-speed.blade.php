<div class="mx-auto max-w-4xl">
    {{-- ══════ Header ══════ --}}
    <div class="mb-6 flex items-start gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
        </div>
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('PageSpeed Insights') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __("Audit any page's performance, accessibility, best practices and SEO on mobile & desktop.") }}
            </p>
        </div>
    </div>

    {{-- ══════ Test form ══════ --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <form wire:submit="runTest" class="space-y-4">
            <div>
                <label for="psi-url" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Page URL') }}</label>
                <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                    <input
                        id="psi-url"
                        type="text"
                        wire:model="url"
                        @disabled($status === 'running')
                        placeholder="https://example.com/page"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        enterkeyhint="go"
                        class="block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm transition focus:border-orange-500 focus:ring-orange-500 disabled:bg-slate-50 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900"
                    >
                    <button
                        type="submit"
                        @disabled($status === 'running')
                        wire:loading.attr="disabled"
                        wire:target="runTest"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-slate-900"
                    >
                        @if ($status === 'running')
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span>{{ __('Testing…') }}</span>
                        @else
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                            <span>{{ __('Analyze') }}</span>
                        @endif
                    </button>
                </div>
                @error('url')
                    <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>
                @enderror
                @if ($status !== 'running')
                    <p class="mt-1.5 text-[11px] text-slate-400 dark:text-slate-500">{{ __('Public URLs only · runs a fresh Lighthouse audit each time') }}</p>
                @endif
            </div>
        </form>

        {{-- Async measurement: poll until both strategies land, with live progress. --}}
        @if ($status === 'running')
            <div
                wire:poll.2s="pollResult"
                x-data="{ s: 0, init() { this.i = setInterval(() => this.s++, 1000) }, destroy() { clearInterval(this.i) } }"
                class="mt-4 rounded-xl border border-orange-200 bg-orange-50/60 p-4 dark:border-orange-500/20 dark:bg-orange-500/5"
                role="status"
                aria-live="polite"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-semibold text-orange-900 dark:text-orange-100">
                        <svg class="h-4 w-4 animate-spin text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        {{ __('Measuring page performance…') }}
                    </div>
                    <span class="font-mono text-xs tabular-nums text-orange-500 dark:text-orange-400"><span x-text="s"></span>s</span>
                </div>
                <p class="mt-1 text-xs leading-relaxed text-orange-700/80 dark:text-orange-300/80">
                    {{ __('Running a full Lighthouse audit on mobile and desktop — usually 20–60s. You can leave this page open.') }}
                </p>
                <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-orange-100 dark:bg-orange-500/20">
                    <div class="h-full w-1/3 animate-pulse rounded-full bg-orange-500"></div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (['mobile' => __('Mobile'), 'desktop' => __('Desktop')] as $key => $label)
                        @php $st = $progress[$key] ?? 'running'; @endphp
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                            'bg-white text-orange-700 ring-1 ring-orange-200 dark:bg-slate-800 dark:text-orange-300 dark:ring-orange-500/30' => $st === 'running',
                            'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30' => $st === 'done',
                            'bg-rose-50 text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/30' => $st === 'failed',
                        ])>
                            @if ($st === 'running')
                                <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            @elseif ($st === 'done')
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                            @else
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                            @endif
                            {{ $label }}
                            <span class="font-normal opacity-70">{{ ['running' => __('analyzing'), 'done' => __('done'), 'failed' => __('failed')][$st] }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($errorMessage)
            <div class="mt-4 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-xs text-rose-700 dark:border-rose-900/40 dark:bg-rose-500/10 dark:text-rose-300" role="alert">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                <span>{{ $errorMessage }}</span>
            </div>
        @endif
    </div>

    {{-- ══════ Results ══════ --}}
    @if ($status === 'done' && is_array($result))
        <div class="mt-6">
            @include('partials.page-speed-report', ['result' => $result, 'testedUrl' => $testedUrl])
        </div>
    @endif
</div>
