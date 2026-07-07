<div class="mx-auto max-w-4xl px-4 py-6">
    @php
        $tones = [
            'critical' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
            'warning'  => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            'serp_gap' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
            'info'     => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
            'good'     => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        ];
    @endphp

    {{-- Header --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-orange-500">{{ __('Fix this keyword') }}</p>
        <h1 class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100">{{ $keyword }}</h1>
        @if ($pageUrl !== '')
            <a href="{{ $pageUrl }}" target="_blank" rel="noopener"
                class="mt-1 inline-block max-w-full truncate text-sm text-orange-600 hover:underline dark:text-orange-400">
                {{ $pageUrl }}
            </a>
        @endif
    </div>

    @if ($status === 'failed')
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-6 text-center dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="text-sm font-medium text-rose-700 dark:text-rose-300">{{ $error }}</p>
            @if ($keyword !== '' && $pageUrl !== '')
                <button type="button" wire:click="retry"
                    class="mt-3 inline-flex items-center gap-1 rounded-md bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-rose-500">
                    {{ __('Try again') }}
                </button>
            @endif
        </div>
    @elseif (in_array($status, ['idle', 'queued', 'running'], true))
        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"
            wire:poll.3s="pollAudit">
            <div class="flex items-center gap-3">
                <svg class="h-5 w-5 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path>
                </svg>
                <div>
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ __('Analysing this page for “:keyword”…', ['keyword' => $keyword]) }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Running a keyword-aware audit and benchmarking the SERP. This usually takes under a minute.') }}</p>
                </div>
            </div>
            <div class="mt-5 space-y-3">
                <div class="h-12 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-12 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
            </div>
        </div>
    @elseif ($status === 'ready')
        {{-- 1. On-page recommendations --}}
        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('On-page fixes') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('What to change on the page to push “:keyword” onto page one.', ['keyword' => $keyword]) }}</p>

            {{-- Keyword presence + length summary --}}
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach (['in_title' => __('In title'), 'in_h1' => __('In H1'), 'in_meta' => __('In meta description')] as $field => $label)
                    @php $ok = (bool) ($onPageMetrics[$field] ?? false); @endphp
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $ok ? $tones['good'] : $tones['critical'] }}">
                        {{ $ok ? '✓' : '✕' }} {{ $label }}
                    </span>
                @endforeach
                @if (! empty($onPageMetrics['competitor_word_count_median']))
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ ($onPageMetrics['word_count_gap'] ?? 0) > 0 ? $tones['warning'] : $tones['good'] }}">
                        {{ __(':count words · SERP median :median', ['count' => number_format($onPageMetrics['word_count']), 'median' => number_format($onPageMetrics['competitor_word_count_median'])]) }}
                    </span>
                @endif
            </div>

            @if (empty($recommendations))
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">{{ __('No specific on-page issues detected — focus on the content depth and CTR levers below.') }}</p>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($recommendations as $rec)
                        <li class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded px-1.5 py-px text-[10px] font-bold uppercase tracking-wide {{ $tones[$rec['severity']] ?? $tones['info'] }}">{{ str_replace('_', ' ', $rec['severity'] ?? 'info') }}</span>
                                <span class="text-[11px] text-slate-400">{{ $rec['section'] ?? '' }}</span>
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $rec['title'] ?? '' }}</span>
                            </div>
                            @if (! empty($rec['why']))
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $rec['why'] }}</p>
                            @endif
                            @if (! empty($rec['fix']))
                                <p class="mt-1.5 text-xs font-medium text-slate-700 dark:text-slate-300">→ {{ $rec['fix'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 2. AI title + meta rewrites --}}
        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
            @if ($aiAllowed) wire:init="loadSnippetRewrites" @endif>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Title & meta rewrites') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Higher-CTR snippets with the keyword used verbatim — the fastest lever for a striking-distance keyword.') }}</p>
                </div>
                @if ($aiAllowed)
                    <select wire:change="regenerateIntent($event.target.value)"
                        class="rounded-md border-slate-200 bg-white py-1 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <option value="auto" @selected($intent === 'auto')>{{ __('Auto (mixed angles)') }}</option>
                        @foreach ($intents as $key => $meta)
                            <option value="{{ $key }}" @selected($intent === $key)>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            @if (! $aiAllowed)
                <div class="mt-3 rounded-lg border border-dashed border-orange-200 bg-orange-50 p-4 text-sm text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                    {{ __('AI rewrites are part of a higher plan. Upgrade to generate keyword-optimised titles and meta descriptions.') }}
                </div>
            @else
                <div wire:loading.flex wire:target="loadSnippetRewrites,regenerateIntent" class="mt-3 items-center gap-2 text-sm text-slate-500">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                    {{ __('Generating rewrites…') }}
                </div>
                <div wire:loading.remove wire:target="loadSnippetRewrites,regenerateIntent">
                    @if ($snippetRewrites === null)
                        <p class="mt-3 text-sm text-slate-400">{{ __('Preparing…') }}</p>
                    @elseif (! ($snippetRewrites['ok'] ?? false))
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                            @switch($snippetRewrites['error'] ?? '')
                                @case('content_too_short') {{ __('Couldn\'t read enough page copy to rewrite the snippet.') }} @break
                                @case('llm_not_configured') {{ __('AI is not configured on this server.') }} @break
                                @case('rewrites_invalid') {{ __('The model couldn\'t produce in-spec rewrites for this keyword. Try a different angle.') }} @break
                                @default {{ __('Couldn\'t generate rewrites right now. Try a different angle.') }}
                            @endswitch
                        </p>
                    @else
                        <ul class="mt-3 space-y-3" x-data>
                            @foreach ($snippetRewrites['rewrites'] ?? [] as $rw)
                                <li class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $rw['title'] ?? '' }}</p>
                                        <button type="button" class="flex-none text-[11px] font-semibold text-orange-600 hover:underline dark:text-orange-400"
                                            x-on:click="navigator.clipboard.writeText(@js(($rw['title'] ?? '')."\n".($rw['meta'] ?? '')))">{{ __('Copy') }}</button>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ $rw['meta'] ?? '' }}</p>
                                    @if (! empty($rw['rationale']))
                                        <p class="mt-1 text-[11px] text-slate-400">{{ $rw['angle'] ?? '' }} · {{ $rw['rationale'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </section>

        {{-- 3. Content brief / topical gaps --}}
        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
            @if ($aiAllowed) wire:init="loadBrief" @endif>
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Content brief & topical gaps') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Subtopics and depth the page needs to climb from page two.') }}</p>

            @if (! $aiAllowed)
                <div class="mt-3 rounded-lg border border-dashed border-orange-200 bg-orange-50 p-4 text-sm text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                    {{ __('Content briefs are part of a higher plan.') }}
                </div>
            @else
                <div wire:loading.flex wire:target="loadBrief,generateBrief" class="mt-3 items-center gap-2 text-sm text-slate-500">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                    {{ __('Building brief…') }}
                </div>
                <div wire:loading.remove wire:target="loadBrief,generateBrief">
                    @if ($brief !== null && ($brief['ok'] ?? false))
                        @php $b = $brief['brief'] ?? []; @endphp
                        <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                            @if (! empty($b['recommended_word_count']))
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 dark:bg-slate-800">{{ __('Target :count words', ['count' => number_format($b['recommended_word_count'])]) }}</span>
                            @endif
                            @if (! empty($b['suggested_schema_type']))
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 dark:bg-slate-800">{{ __('Schema: :type', ['type' => $b['suggested_schema_type']]) }}</span>
                            @endif
                        </div>
                        @if (! empty($b['suggested_h1']))
                            <p class="mt-3 text-sm text-slate-700 dark:text-slate-300"><span class="font-semibold">{{ __('Suggested H1:') }}</span> {{ $b['suggested_h1'] }}</p>
                        @endif
                        @if (! empty($b['subtopics']))
                            <div class="mt-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Subtopics to cover') }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($b['subtopics'] as $st)
                                        <span class="rounded-md bg-orange-50 px-2 py-1 text-xs text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">{{ $st }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if (! empty($b['people_also_ask']))
                            <div class="mt-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('People also ask') }}</p>
                                <ul class="mt-1 list-disc space-y-0.5 ps-5 text-xs text-slate-600 dark:text-slate-300">
                                    @foreach ($b['people_also_ask'] as $q)
                                        <li>{{ $q }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @elseif ($brief !== null && ($brief['error'] ?? '') === 'not_generated')
                        <div class="mt-3">
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('No brief generated yet for this keyword.') }}</p>
                            <button type="button" wire:click="generateBrief"
                                class="mt-2 inline-flex items-center gap-1 rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-500">
                                {{ __('Generate brief') }}
                            </button>
                        </div>
                    @elseif ($brief !== null)
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                            {{ ($brief['error'] ?? '') === 'no_serp_data' ? __('Couldn\'t pull a SERP for this keyword right now.') : __('Couldn\'t build a brief right now.') }}
                        </p>
                    @endif
                </div>
            @endif
        </section>

        {{-- 4. Internal-link suggestions --}}
        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Internal links to add') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ __('Add a link') }} <span class="font-semibold">{{ __('to') }}</span>
                <a href="{{ $pageUrl }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline dark:text-orange-400">{{ __('your ranking page') }}</a>
                <span class="font-semibold">{{ __('from') }}</span> {{ __('each page below — these already rank for related queries, so a contextual link (keyword as anchor) passes the most relevance.') }}
            </p>

            @if (empty($internalLinks))
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('No other pages on your site rank for related queries yet, so there are no internal-link sources to suggest.') }}</p>
            @else
                <p class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Add the link from these pages') }}</p>
                <ul class="mt-1.5 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($internalLinks as $link)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener"
                                    class="block truncate text-sm text-orange-600 hover:underline dark:text-orange-400">{{ $link['url'] }}</a>
                                <p class="truncate text-[11px] text-slate-400">{{ __('anchor: “:anchor”', ['anchor' => $link['anchor_hint'] ?? $keyword]) }}</p>
                            </div>
                            <span class="flex-none text-[11px] font-semibold tabular-nums text-slate-500 dark:text-slate-400">{{ __(':count clicks', ['count' => number_format($link['clicks_30d'] ?? 0)]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
