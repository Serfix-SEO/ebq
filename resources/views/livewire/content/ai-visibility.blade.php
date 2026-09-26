{{-- AI Visibility — what we can prove about AI answer engines and this site.

     Three panels, deliberately separated because they carry different weights
     of evidence: what the site ALLOWS (always knowable), what actually FETCHED
     it (only where our plugin or kit runs), and who ARRIVED from an AI answer
     (needs GA). Every panel has an explicit "we cannot see this yet" state —
     a blank zero and an unmeasurable signal must never look the same.

     Chart geometry is an inline SVG polyline, same as keyword-rank-history:
     no chart library, and every utility class here already exists in the
     committed Tailwind build. --}}
<div class="space-y-5">
    {{-- The teaser. Free signups see a complete example report, and the one
         thing that must never happen is a client mistaking it for their own
         site — so this says so at the top in the largest type on the page, and
         every panel below repeats it in a chip. The numbers themselves come
         from AeoSampleData and touch nothing belonging to this account. --}}
    @if ($sample)
        <div class="overflow-hidden rounded-2xl border-2 border-orange-300 bg-gradient-to-br from-orange-50 to-white shadow-sm dark:border-orange-700 dark:from-orange-950/60 dark:to-slate-900">
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:gap-6 sm:p-6">
                <div class="min-w-0 flex-1">
                    <p class="text-2xl font-extrabold leading-tight tracking-tight text-orange-700 sm:text-3xl dark:text-orange-300">
                        {{ __('This is sample data — not your website') }}
                    </p>
                    <p class="mt-2 text-base font-medium text-slate-700 sm:text-lg dark:text-slate-200">
                        {{ __('Every number below is an example. Start your trial for $:p to see which AI engines can read your site, which ones actually crawl it, and how many people reach you from an AI answer.', ['p' => \App\Support\ContentAutopilotConfig::displayPrice('first_month')]) }}
                    </p>
                </div>
                <a href="{{ $checkoutUrl }}"
                   class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-orange-600 px-6 py-4 text-base font-extrabold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700 sm:text-lg">
                    {{ __('Start for $:p', ['p' => \App\Support\ContentAutopilotConfig::displayPrice('first_month')]) }}
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        </div>
    @endif

    @if ($website === null)
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('Add a website to see how AI answer engines treat it.') }}
        </div>
    @else
        {{-- ── Readiness ───────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">
                        {{ __('Can AI answer engines read your site?') }}
                        @if ($sample)<span class="ms-1.5 rounded-full bg-orange-100 px-2 py-0.5 align-middle text-xs font-extrabold uppercase tracking-wide text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ __('Sample') }}</span>@endif
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                        {{ __('ChatGPT, Perplexity, Claude and Gemini each use their own crawler. Blocking one keeps you out of its answers, whatever your Google ranking says.') }}
                    </p>
                </div>
                @if ($audit)
                    @php
                        $score = (int) $audit->readiness_score;
                        $tone = $score >= 85 ? 'success' : ($score >= 60 ? 'amber' : 'error');
                        $ring = $score >= 85 ? 'rgb(16 185 129)' : ($score >= 60 ? 'rgb(245 158 11)' : 'rgb(239 68 68)');
                        $circ = 2 * M_PI * 34;
                    @endphp
                    <div class="flex shrink-0 items-center gap-3">
                        <div class="relative h-20 w-20">
                            <svg viewBox="0 0 80 80" class="h-20 w-20 -rotate-90">
                                <circle cx="40" cy="40" r="34" fill="none" stroke="currentColor" stroke-width="8" class="text-slate-100 dark:text-slate-800"></circle>
                                <circle cx="40" cy="40" r="34" fill="none" stroke="{{ $ring }}" stroke-width="8" stroke-linecap="round"
                                        stroke-dasharray="{{ round($circ, 1) }}"
                                        stroke-dashoffset="{{ round($circ * (1 - $score / 100), 1) }}"></circle>
                            </svg>
                            <span class="absolute inset-0 flex items-center justify-center text-lg font-extrabold text-slate-900 dark:text-slate-100">{{ $score }}</span>
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            <p class="font-semibold text-slate-700 dark:text-slate-200">{{ __('AI readiness') }}</p>
                            <p class="mt-0.5">{{ __('Checked :when', ['when' => $audit->checked_at?->diffForHumans()]) }}</p>
                            @unless ($sample)
                                <button wire:click="recheck" wire:loading.attr="disabled" wire:target="recheck"
                                        class="mt-1 font-semibold text-orange-600 hover:text-orange-700 disabled:opacity-50">
                                    {{ $checkQueued ? __('Checking…') : __('Check again') }}
                                </button>
                            @endunless
                        </div>
                    </div>
                @endif
            </div>

            @if (! $audit)
                <p class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    {{ __('We haven\'t checked this site yet — the first check runs within a week of setup.') }}
                    <button wire:click="recheck" class="font-semibold text-orange-600 hover:text-orange-700">{{ $checkQueued ? __('Checking…') : __('Check now') }}</button>
                </p>
            @else
                @unless ($audit->robots_fetched)
                    <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                        {{ __('We couldn\'t read your robots.txt on the last check, so the access column below is unknown rather than confirmed.') }}
                    </p>
                @endunless

                <div class="mt-4 space-y-2">
                    @foreach ($audit->breakdown ?? [] as $check)
                        <div class="flex items-start gap-2.5 text-sm">
                            @if ($check['passed'])
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            @else
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 9v3.75m0 3.75h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            @endif
                            <span class="min-w-0 text-slate-700 dark:text-slate-200">
                                {{ $check['label'] }}
                                @if (! empty($check['detail']))
                                    <span class="text-slate-400 dark:text-slate-500">· {{ $check['detail'] }}</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Per-agent access + activity ─────────────────────────── --}}
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">
                    {{ __('AI crawlers') }}
                    @if ($sample)<span class="ms-1.5 rounded-full bg-orange-100 px-2 py-0.5 align-middle text-xs font-extrabold uppercase tracking-wide text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ __('Sample') }}</span>@endif
                </h2>
                @if ($hits['instrumented'] && $hits['stale'])
                    <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                        {{ __('Reporting paused — last report :date', ['date' => $hits['last_report']]) }}
                    </span>
                @elseif ($hits['instrumented'])
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ __(':n visits in the last 30 days', ['n' => number_format($hits['total'])]) }}</span>
                @endif
            </div>

            @unless ($hits['instrumented'])
                <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-300">
                    {{ __('We can show which AI crawlers actually fetch your pages once our plugin or publishing kit is installed on your site.') }}
                    @if ($canInstrument)
                        <a href="{{ route('content.integrations') }}" wire:navigate class="font-semibold text-orange-600 hover:text-orange-700">{{ __('Set that up →') }}</a>
                    @endif
                </div>
            @endunless

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50/70 text-left text-xs font-bold uppercase tracking-wide text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-2">{{ __('Crawler') }}</th>
                            {{-- Below sm there is no room for five columns and the
                                 fetch count — the number the panel exists for — was the
                                 one being clipped. Purpose and last-seen are the
                                 droppable two. --}}
                            <th class="hidden px-3 py-2 sm:table-cell">{{ __('Used for') }}</th>
                            <th class="px-3 py-2">{{ __('Allowed') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Fetches (30d)') }}</th>
                            <th class="hidden px-5 py-2 text-right sm:table-cell">{{ __('Last seen') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($agents as $agent)
                            <tr wire:key="agent-{{ $agent['id'] }}">
                                <td class="px-5 py-2.5">
                                    <span class="font-semibold text-slate-800 dark:text-slate-100">{{ $agent['label'] }}</span>
                                    <span class="ms-1 text-xs text-slate-400 dark:text-slate-500">{{ $agent['engine'] }}</span>
                                </td>
                                <td class="hidden px-3 py-2.5 text-xs text-slate-500 sm:table-cell dark:text-slate-400">
                                    {{ $agent['kind'] === 'search' ? __('Answer citations') : ($agent['kind'] === 'user' ? __('Opening your page for a user') : __('Model training')) }}
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($agent['access'] === 'allowed')
                                        <span class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">{{ __('Yes') }}</span>
                                    @elseif ($agent['access'] === 'blocked')
                                        <span class="rounded-full bg-error/10 px-2 py-0.5 text-xs font-bold text-error">{{ __('Blocked') }}</span>
                                    @else
                                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ __('Unknown') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 pe-5 text-right tabular-nums text-slate-700 sm:pe-3 dark:text-slate-200">
                                    @if (! $agent['crawls'])
                                        <span class="text-xs text-slate-400 dark:text-slate-500" title="{{ __('This name only exists as a robots.txt setting — it never visits pages.') }}">—</span>
                                    @elseif (! $hits['instrumented'])
                                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ __('not tracked') }}</span>
                                    @else
                                        {{ number_format($agent['hits']) }}
                                        @if ($agent['pages'] > 0)
                                            <span class="block text-xs font-normal text-slate-400 dark:text-slate-500">{{ __(':n pages', ['n' => number_format($agent['pages'])]) }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="hidden px-5 py-2.5 text-right text-xs text-slate-500 sm:table-cell dark:text-slate-400">
                                    {{ $agent['last_seen'] ? \Illuminate\Support\Carbon::parse($agent['last_seen'])->translatedFormat('M j') : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Visits from AI answers ──────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">
                    {{ __('Visits from AI answers') }}
                    @if ($sample)<span class="ms-1.5 rounded-full bg-orange-100 px-2 py-0.5 align-middle text-xs font-extrabold uppercase tracking-wide text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ __('Sample') }}</span>@endif
                </h2>
                @if ($referrals['connected'])
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ __('Last 90 days') }}</span>
                @endif
            </div>

            @unless ($referrals['connected'])
                <p class="mt-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                    {{ __('Connect Google Analytics to see how many people reach your site from ChatGPT, Perplexity, Gemini and Copilot.') }}
                    <a href="{{ route('content.integrations') }}" wire:navigate class="font-semibold text-orange-600 hover:text-orange-700">{{ __('Connect →') }}</a>
                </p>
            @else
                @if ($referrals['total'] === 0)
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('No visits from an AI answer yet. They start once the engines have crawled and begun citing your pages.') }}</p>
                @else
                    <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                        <div>
                            <p class="text-2xl font-extrabold tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($referrals['total']) }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('visits from AI answers') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($referrals['engines'] as $engine => $sessions)
                                <span wire:key="eng-{{ $engine }}" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    {{ $engine }} · {{ number_format($sessions) }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $series = $referrals['series'];
                        $maxS = max(1, max(array_column($series, 'sessions') ?: [1]));
                        $W = 720; $H = 120; $PAD = 6;
                        $step = count($series) > 1 ? ($W - 2 * $PAD) / (count($series) - 1) : 0;
                        $pts = [];
                        foreach ($series as $i => $p) {
                            $pts[] = round($PAD + $i * $step, 1).','.round($H - $PAD - ($p['sessions'] / $maxS) * ($H - 2 * $PAD), 1);
                        }
                    @endphp
                    @if (count($pts) > 1)
                        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="mt-4 w-full" preserveAspectRatio="none" role="img"
                             aria-label="{{ __('Visits from AI answers over the last 90 days') }}">
                            <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="rgb(249 115 22)" stroke-width="2.5"
                                      stroke-linejoin="round" stroke-linecap="round"></polyline>
                        </svg>
                    @endif
                @endif
            @endunless
        </div>

        @if ($sample)
            <div class="rounded-2xl border-2 border-orange-300 bg-orange-50 p-5 text-center dark:border-orange-700 dark:bg-orange-950/50">
                <p class="text-xl font-extrabold text-orange-700 sm:text-2xl dark:text-orange-300">{{ __('That was an example. Want to see yours?') }}</p>
                <a href="{{ $checkoutUrl }}"
                   class="mt-3 inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 px-6 py-3.5 text-base font-extrabold text-white shadow-lg shadow-orange-600/25 transition hover:bg-orange-700">
                    {{ __('Start for $:p', ['p' => \App\Support\ContentAutopilotConfig::displayPrice('first_month')]) }}
                </a>
            </div>
        @endif
    @endif
</div>
