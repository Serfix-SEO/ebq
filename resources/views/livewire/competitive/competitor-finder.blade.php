<div @if ($this->isPolling()) wire:poll.3s="poll" @endif>
    {{-- Search bar: any website URL, defaults to the current site. --}}
    <form wire:submit.prevent="run" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <label class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Website to analyze') }}</label>
        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247" /></svg>
                <input type="text" wire:model="url" placeholder="example.com" autocomplete="off"
                       class="h-11 w-full rounded-lg border border-slate-300 bg-white ps-9 pe-3 text-sm shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="run"
                    class="inline-flex h-11 flex-none items-center justify-center gap-2 rounded-lg bg-orange-600 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500/40 disabled:opacity-60">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                {{ __('Find competitors') }}
            </button>
        </div>
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
            {{ __('Works for any site — even brand-new ones with no backlink data. We read the site’s keywords, verify they’re real, and find who ranks alongside it in search.') }}
            @if (($fleetRemaining ?? null) !== null)
                <span class="ms-1 tabular-nums {{ $fleetRemaining <= 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-slate-400' }}">
                    · {{ $fleetRemaining <= 0 ? __('No keyword searches left this month') : __(':n keyword searches left this month', ['n' => number_format($fleetRemaining)]) }}
                </span>
            @endif
        </p>
        @error('url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        @if ($errorMessage)
            @if (\App\Support\QuotaMessage::isQuota($errorMessage))
                <x-quota-alert :message="$errorMessage" />
            @else
                <p class="mt-2 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $errorMessage }}</p>
            @endif
        @endif
    </form>

    {{-- Progress --}}
    @if ($this->isPolling())
        @php
            $steps = [
                ['key' => 'finding_keywords', 'label' => __('Reading the site’s keywords')],
                ['key' => 'discovering', 'label' => __('Searching for competitors in the results')],
            ];
            $activeIndex = $status === 'discovering' ? 1 : 0;
        @endphp
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center gap-3">
                <span class="h-5 w-5 flex-none animate-spin rounded-full border-2 border-slate-200 border-t-orange-500 dark:border-slate-700"></span>
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                    {{ $status === 'discovering' ? __('Searching the web for :d’s competitors…', ['d' => $domain]) : __('Analyzing :d…', ['d' => $domain]) }}
                </p>
            </div>
            <ol class="mt-4 space-y-2">
                @foreach ($steps as $i => $step)
                    <li class="flex items-center gap-2.5 text-sm">
                        @if ($i < $activeIndex)
                            <svg class="h-4 w-4 flex-none text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                        @elseif ($i === $activeIndex)
                            <span class="h-4 w-4 flex-none animate-spin rounded-full border-2 border-slate-200 border-t-orange-500 dark:border-slate-700"></span>
                        @else
                            <span class="h-4 w-4 flex-none rounded-full border-2 border-slate-200 dark:border-slate-700"></span>
                        @endif
                        <span @class(['text-slate-700 dark:text-slate-200' => $i <= $activeIndex, 'text-slate-400 dark:text-slate-500' => $i > $activeIndex])>{{ $step['label'] }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="mt-4 text-xs text-slate-400 dark:text-slate-500">{{ __('This can take up to a minute for a new site. It keeps running if you wait here.') }}</p>
        </div>
    @endif

    {{-- Results --}}
    @if ($status === 'done' && ! empty($competitors))
        @php
            $score = fn ($v) => ($v ?? null) !== null ? number_format((float) $v, 1).'/10' : '—';
            $fmt = fn ($v) => is_numeric($v) ? number_format((int) $v) : '—';
        @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-col gap-2 border-b border-slate-100 p-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Competitors for :d', ['d' => $domain]) }}</h2>
                    <p class="mt-0.5 inline-flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                        {{ __(':n domains ranking alongside this site in search.', ['n' => count($competitors)]) }}
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-800">
                            @switch($querySource)
                                @case('report') {{ __('From this site’s report') }} @break
                                @case('page_content') {{ __('Found via page content') }} @break
                                @default {{ __('Found via the site’s keywords') }}
                            @endswitch
                        </span>
                    </p>
                </div>
                <a href="{{ route('keyword-gap.index', ['url' => $domain]) }}" wire:navigate
                   class="inline-flex flex-none items-center justify-center gap-2 rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700">
                    {{ __('Compare in Keyword Gap') }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                </a>
            </div>
            <div class="max-h-[560px] overflow-x-auto overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10">
                        <tr class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/70 dark:text-slate-400">
                            <th class="w-10 px-5 py-2 text-left">#</th>
                            <th class="px-3 py-2 text-left">{{ __('Domain') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Appearances') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Avg position') }}</th>
                            <th class="px-5 py-2 text-right">{{ __('Authority') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($competitors as $i => $row)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-2.5 tabular-nums text-slate-400 dark:text-slate-500">{{ $i + 1 }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center gap-2">
                                        <img src="https://www.google.com/s2/favicons?domain={{ urlencode($row['domain'] ?? '') }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800" loading="lazy" onerror="this.style.visibility='hidden'">
                                        <a href="{{ route('report.view', ['url' => $row['domain'] ?? '']) }}" class="font-medium text-slate-800 hover:text-orange-600 hover:underline dark:text-slate-200 dark:hover:text-orange-400">{{ $row['domain'] ?? '' }}</a>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $fmt($row['shared_keywords'] ?? null) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ isset($row['avg_position']) && $row['avg_position'] !== null ? number_format((float) $row['avg_position'], 1) : '—' }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $score($row['opr_score'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 dark:border-slate-800 dark:text-slate-500">
                {{ __('Click a domain to open its Site Explorer report. “Appearances” = how many of the site’s search results this competitor also ranked in.') }}
            </p>
        </div>
    @elseif (in_array($status, ['done', 'no_keywords'], true) && empty($competitors))
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <svg class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75l-2.489-2.489m0 0a3.375 3.375 0 10-4.773-4.773 3.375 3.375 0 004.774 4.774zM21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-300">{{ __('No clear competitors found for :d yet.', ['d' => $domain]) }}</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('The site may be too new or too niche to share search results with others. Try again later, or add competitors manually in the Keyword Gap tool.') }}</p>
        </div>
    @endif
</div>
