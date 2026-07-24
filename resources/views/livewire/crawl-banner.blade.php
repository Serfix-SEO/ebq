<div wire:poll.{{ $pollInterval }}s>
    @if ($crawl)
        <div class="flex items-start gap-3 rounded-xl border border-orange-200 bg-orange-50 p-5 dark:border-orange-500/30 dark:bg-orange-500/10">
            <x-nodus state="analyzing" :size="40" class="flex-none text-orange-400 dark:text-orange-500" />
            @php
                // $crawled / $total / $remainingCap are computed in CrawlBanner::render
                // (from the live page inventory, not the per-pass run counters).
                $finalizing = $crawl->status === \App\Models\CrawlRun::STATUS_FINALIZING;
            @endphp
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                    @if ($finalizing) {{ __('We’re computing your results') }} @else {{ __('We’re crawling your website right now') }} @endif
                </h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    @if ($finalizing)
                        {{ __('The crawl finished — we’re scoring your pages and building Site Health, page-level issues and SEO scores. This page fills in automatically when it’s done.') }}
                    @else
                        {{ __('We’re fetching and analysing your pages to build Site Health, page-level issues and SEO scores. This usually takes a few minutes — the dashboard will fill in automatically as the crawl progresses.') }}
                    @endif
                </p>
                @if (! $finalizing && $total > 0)
                    <p class="mt-2 text-xs font-medium text-orange-700 dark:text-orange-300">
                        {{ number_format($crawled) }} / {{ number_format($total) }} {{ __('pages crawled') }}
                    </p>
                @endif
                @if ($remainingCap !== null)
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Plan allowance remaining:') }} {{ number_format($remainingCap) }} {{ __('of') }} {{ number_format($cap) }}
                    </p>
                @endif
            </div>
        </div>
    @elseif ($initialPending)
        {{-- First crawl dispatched but the run row isn't up yet (queued / syncing
             sitemaps / waiting on a worker). Stands in so a brand-new site never
             shows the empty "all caught up" state before the crawl actually starts. --}}
        <div class="flex items-start gap-3 rounded-xl border border-orange-200 bg-orange-50 p-5 dark:border-orange-500/30 dark:bg-orange-500/10">
            <x-nodus state="analyzing" :size="40" class="flex-none text-orange-400 dark:text-orange-500" />
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                    {{ __('We’re setting up your site') }}
                </h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    {{ __('Your first crawl is queued and will begin in a moment. We’ll fetch and analyse your pages to build Site Health, page-level issues and SEO scores — this page fills in automatically as the crawl progresses.') }}
                </p>
            </div>
        </div>
    @endif
</div>
