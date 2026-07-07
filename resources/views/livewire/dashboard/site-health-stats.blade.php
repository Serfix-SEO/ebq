<div>
    {{-- Hidden while the first crawl runs (the crawl-in-progress banner stands
         in); shown once a crawl has finished and real data exists. --}}
    @if (! $hide && $summary && $summary['has_crawl'])
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Site Health') }}</h2>
                <a href="{{ route('link-structure.index') }}" wire:navigate class="text-[11px] font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400">{{ __('Link structure') }} →</a>
            </div>

            @if (! empty($partial))
                <div class="mb-3 rounded-md bg-orange-50 px-3 py-1.5 text-[11px] text-orange-800 dark:bg-orange-500/10 dark:text-orange-200">
                    ⏳ {{ __('Partial results — the crawl is still running, so these numbers will change once it finishes.') }}
                </div>
            @endif

            @if ($summary['blocked'])
                <div class="mb-3 rounded-md bg-red-50 px-3 py-1.5 text-[11px] text-red-800 dark:bg-red-900/30 dark:text-red-200">
                    {{ __("We can't crawl this site — the server is blocking our crawler") }} ({{ str_replace('_', ' ', (string) $summary['blocked_reason']) }}).
                </div>
            @endif

            {{-- Allowlist guidance: shown when the site blocks us or sits behind
                 a WAF/CDN. Lets the owner let our crawler through. --}}
            @if (($summary['blocked'] || in_array($protection, ['cloudflare','siteground','blocked'], true) || ($protection !== null && $protection !== '')) && $egressIp)
                <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <div class="font-semibold">
                        @if ($protection === 'cloudflare') {{ __('This site is behind Cloudflare.') }}
                        @elseif ($protection === 'siteground') {{ __('This site is behind SiteGround CDN.') }}
                        @elseif ($protection !== null && $protection !== 'blocked') {{ __('This site is behind a WAF/CDN') }} ({{ $protection }}).
                        @else {{ __('This site is blocking our crawler.') }}
                        @endif
                        {{ __('To get a complete crawl, allowlist us:') }}
                    </div>
                    <div class="mt-1 space-y-0.5">
                        <div>{{ __('IP address:') }} <code class="rounded bg-amber-100 px-1 dark:bg-amber-500/20">{{ $egressIp }}</code></div>
                        <div class="truncate">{{ __('User-Agent:') }} <code class="rounded bg-amber-100 px-1 dark:bg-amber-500/20">{{ $crawlerUa }}</code></div>
                    </div>
                    @if ($protection === 'cloudflare')
                        <div class="mt-1 text-amber-700 dark:text-amber-300/80">{{ __('In Cloudflare: WAF → Tools → IP Access Rules → Allow this IP (or skip Bot Fight Mode for it).') }}</div>
                    @elseif ($protection === 'siteground')
                        <div class="mt-1 text-amber-700 dark:text-amber-300/80">{{ __('In SiteGround: Security → Anti-Bot Protection → add this IP to the allowlist, or disable bot protection for your domain.') }}</div>
                    @else
                        <div class="mt-1 text-amber-700 dark:text-amber-300/80">{{ __('Add this IP and User-Agent to your WAF/CDN allowlist or firewall rules.') }}</div>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                    <div class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ __('Health score') }}</div>
                    <div class="text-xl font-bold {{ ($summary['health_score'] ?? 0) >= 80 ? 'text-emerald-600' : (($summary['health_score'] ?? 0) >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $summary['health_score'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ __('Pages') }}</div>
                    <div class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ number_format($summary['pages_total']) }}</div>
                </div>
                <div>
                    <div class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ __('Open issues') }}</div>
                    <div class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ number_format($summary['findings']['total']) }}</div>
                    <div class="text-[10px] text-red-500">{{ $summary['findings']['critical'] }} {{ __('crit') }} · {{ $summary['findings']['high'] }} {{ __('high') }}</div>
                </div>
                <div>
                    <div class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ __('Orphans') }}</div>
                    <div class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ number_format($summary['orphan_count']) }}</div>
                </div>
            </div>
            <p class="mt-2 text-[10px] text-slate-400">{{ __('Detailed issues are in the Priority Action Queue below.') }}</p>
        </div>
    @endif
</div>
