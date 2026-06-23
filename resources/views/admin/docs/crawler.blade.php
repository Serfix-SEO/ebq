<x-layouts.app>
    <div class="mx-auto max-w-4xl space-y-8 pb-16">
        <header>
            <h1 class="text-2xl font-bold tracking-tight">Site Crawler &amp; SEO Intelligence — Admin Guide</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Operator documentation for the website crawler that powers Site Health, the Priority Action Queue, AI context, and growth reports.</p>
        </header>

        @php
            $h2 = 'mt-8 mb-2 text-lg font-semibold text-slate-900 dark:text-slate-100';
            $h3 = 'mt-4 mb-1 text-sm font-semibold text-slate-800 dark:text-slate-200';
            $p = 'text-sm leading-relaxed text-slate-600 dark:text-slate-300';
            $li = 'text-sm text-slate-600 dark:text-slate-300';
            $code = 'rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[12px] text-slate-800 dark:bg-slate-800 dark:text-slate-200';
            $box = 'rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900';
        @endphp

        {{-- Overview --}}
        <section>
            <h2 class="{{ $h2 }}">1. What it does</h2>
            <p class="{{ $p }}">On client add (and on a recurring schedule) the crawler discovers every page of a client's site from <strong>both</strong> Google Search Console and the XML sitemap, fetches and analyzes each page, and stores a per-site page inventory, internal-link graph, and a unified issue catalog. That intelligence feeds four surfaces: the dashboard <strong>Site Health</strong> stats widget + <strong>Priority Action Queue</strong> (issues drill down to affected URLs), the <strong>Link Structure</strong> page (per-page inbound/outbound links), the <strong>AI ContextBuilder</strong> (a <span class="{{ $code }}">site_intel</span> signal), and <strong>growth reports</strong>.</p>
        </section>

        {{-- Architecture --}}
        <section>
            <h2 class="{{ $h2 }}">2. Architecture &amp; data model</h2>
            <div class="{{ $box }}">
                <p class="{{ $p }} font-mono text-[12px]">CrawlWebsitePagesJob &nbsp;→&nbsp; (frontier: GSC ∪ sitemap) &nbsp;→&nbsp; batch of CrawlPageBatchJob &nbsp;→&nbsp; AnalyzeSiteJob</p>
            </div>
            <ul class="mt-3 space-y-1.5">
                <li class="{{ $li }}">• <span class="{{ $code }}">website_pages</span> — one row per URL: HTTP/indexability state, conditional-GET caches (etag/last-modified/content_hash), word count, link counts, click depth, page score, <span class="{{ $code }}">next_crawl_at</span>.</li>
                <li class="{{ $li }}">• <span class="{{ $code }}">website_internal_links</span> — directed link graph. <span class="{{ $code }}">status='discovered'</span> = real edges; <span class="{{ $code }}">'suggested'</span> = AI/heuristic internal-link suggestions.</li>
                <li class="{{ $li }}">• <span class="{{ $code }}">crawl_runs</span> — per-run metadata + counters (pages_seen/fetched/304/changed/error), health_score, blocked_reason.</li>
                <li class="{{ $li }}">• <span class="{{ $code }}">crawl_findings</span> — the unified issue catalog (one source for all surfaces). Idempotent upsert by (website, type, url hash); stale findings auto-resolve.</li>
            </ul>
        </section>

        {{-- Discovery --}}
        <section>
            <h2 class="{{ $h2 }}">3. Discovery</h2>
            <p class="{{ $p }}">The frontier is the <strong>union</strong> of distinct <span class="{{ $code }}">search_console_data.page</span> values and every <span class="{{ $code }}">&lt;loc&gt;</span> across the site's sitemaps (sitemap index recursion, nested sitemaps, and <span class="{{ $code }}">.xml.gz</span> are handled; recursion depth and cycles are capped). URLs are normalized + sha-256 hashed for dedup. Internal links found on a page also create (stub) inventory rows so the graph stays complete.</p>
        </section>

        {{-- Recrawl --}}
        <section>
            <h2 class="{{ $h2 }}">4. Recrawl optimization</h2>
            <p class="{{ $p }}">Every URL is re-verified each weekly cycle (full coverage), but cheaply: a stored <strong>ETag/Last-Modified</strong> is sent as a conditional GET — a <span class="{{ $code }}">304</span> means zero parsing; a <span class="{{ $code }}">200</span> with an unchanged <strong>content hash</strong> skips re-analysis. Only genuinely changed pages run the full finding pipeline. New sitemap URLs are picked up daily (see §6). Politeness: per-host delay (<span class="{{ $code }}">crawler.delay_ms</span>) and SSRF guarding on every hop. Over-plan-limit (<span class="{{ $code }}">isFrozen()</span>) sites are skipped.</p>
        </section>

        {{-- Findings catalog --}}
        <section>
            <h2 class="{{ $h2 }}">5. Findings catalog</h2>
            <div class="overflow-x-auto {{ $box }} p-0">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-100 bg-slate-50 uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50">
                        <tr><th class="px-3 py-2">Category</th><th class="px-3 py-2">Example types</th><th class="px-3 py-2">Default severity</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr><td class="px-3 py-2 font-medium">crawlability</td><td class="px-3 py-2">crawl_blocked (CAPTCHA/403/429)</td><td class="px-3 py-2 text-red-600">critical</td></tr>
                        <tr><td class="px-3 py-2 font-medium">broken_link</td><td class="px-3 py-2">broken_internal, broken_external, broken_page</td><td class="px-3 py-2 text-red-600">critical / high</td></tr>
                        <tr><td class="px-3 py-2 font-medium">indexability</td><td class="px-3 py-2">noindex_important, canonical_mismatch, missing_self_hreflang, hreflang_canonical_conflict</td><td class="px-3 py-2 text-amber-600">high</td></tr>
                        <tr><td class="px-3 py-2 font-medium">internal_links</td><td class="px-3 py-2">orphan_page, deep_page</td><td class="px-3 py-2 text-amber-600">high / medium</td></tr>
                        <tr><td class="px-3 py-2 font-medium">onpage</td><td class="px-3 py-2">missing/dup title &amp; meta, duplicate_content, missing/multiple h1, thin_content, missing_image_alt, missing_open_graph, missing_twitter_card</td><td class="px-3 py-2 text-slate-500">medium / low</td></tr>
                        <tr><td class="px-3 py-2 font-medium">redirect</td><td class="px-3 py-2">redirecting_url, redirect_chain_too_long, external_redirect</td><td class="px-3 py-2 text-slate-500">low</td></tr>
                        <tr><td class="px-3 py-2 font-medium">sitemap</td><td class="px-3 py-2">indexed_not_in_sitemap, sitemap_broken_url, sitemap_redirect_url, sitemap_noindex_url</td><td class="px-3 py-2 text-slate-500">low / medium</td></tr>
                        <tr><td class="px-3 py-2 font-medium">schema</td><td class="px-3 py-2">missing_structured_data, invalid_structured_data</td><td class="px-3 py-2 text-slate-500">low</td></tr>
                        <tr><td class="px-3 py-2 font-medium">performance</td><td class="px-3 py-2">slow_response</td><td class="px-3 py-2 text-slate-500">medium / low</td></tr>
                        <tr><td class="px-3 py-2 font-medium">security</td><td class="px-3 py-2">mixed_content</td><td class="px-3 py-2 text-amber-600">high / medium</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 {{ $p }}"><strong>Impact</strong> is the page's 28-day GSC clicks (dollarizable), used to rank findings within a severity tier. Internal 404s with traffic are bridged into the existing <span class="{{ $code }}">RedirectSuggestion</span> pipeline.</p>
        </section>

        {{-- Operations --}}
        <section>
            <h2 class="{{ $h2 }}">6. Operations runbook</h2>
            <div class="{{ $box }} space-y-2 font-mono text-[12px]">
                <div># Crawl one site now (force = ignore recrawl schedule)</div>
                <div class="text-indigo-600 dark:text-indigo-400">php artisan ebq:crawl-websites --website=&lt;id&gt; --force</div>
                <div class="pt-1"># One-off: crawl every existing site that was never crawled (run once after deploy)</div>
                <div class="text-indigo-600 dark:text-indigo-400">php artisan ebq:crawl-websites --backfill</div>
                <div class="pt-1"># Daily sitemap-delta check (also scheduled): crawl only brand-new sitemap URLs</div>
                <div class="text-indigo-600 dark:text-indigo-400">php artisan ebq:crawl-websites --sitemap-deltas</div>
            </div>
            <ul class="mt-3 space-y-1.5">
                <li class="{{ $li }}">• <strong>Schedule</strong>: weekly full recrawl (Mon 02:00) + daily sitemap-delta (04:30). On client add, a chain runs <span class="{{ $code }}">SyncSitemaps → CrawlWebsitePagesJob</span>.</li>
                <li class="{{ $li }}">• <strong>Read a run</strong>: <span class="{{ $code }}">crawl_runs</span> — a healthy recrawl shows high <span class="{{ $code }}">pages_304</span> and low <span class="{{ $code }}">pages_changed</span>. Statuses: running / completed / failed / <strong>aborted</strong> (wholesale-blocked).</li>
                <li class="{{ $li }}">• <strong>Blocked site</strong>: status <span class="{{ $code }}">aborted</span> + a <span class="{{ $code }}">blocked_reason</span> (blocked/captcha/rate_limited/login_required) and a single <span class="{{ $code }}">crawlability</span> finding. Site Health shows a red banner. Tell the client to allowlist our Googlebot-style UA or relax the bot-wall.</li>
                <li class="{{ $li }}">• <strong>Idempotent</strong>: re-running never duplicates rows (upsert by url hash / finding key).</li>
            </ul>
            <h3 class="{{ $h3 }}">Known limitations</h3>
            <ul class="space-y-1">
                <li class="{{ $li }}">• JS-rendered SPAs: we only see server HTML, so client-rendered content/links may look thin/orphaned.</li>
                <li class="{{ $li }}">• Bot-walls (429/403/CAPTCHA): detected and reported, not bypassed.</li>
                <li class="{{ $li }}">• Faceted-nav / calendar traps: bounded by recursion/stub caps + per-host throttle.</li>
            </ul>
            <h3 class="{{ $h3 }}">Config (config/crawler.php)</h3>
            <p class="{{ $p }}"><span class="{{ $code }}">batch_size</span>, <span class="{{ $code }}">delay_ms</span>, <span class="{{ $code }}">timeout</span>, <span class="{{ $code }}">max_external_checks</span>, <span class="{{ $code }}">deep_page_threshold</span>, <span class="{{ $code }}">important_clicks</span>. AI advice uses the app's <span class="{{ $code }}">LlmClient</span> abstraction.</p>
        </section>

        {{-- Visual testing --}}
        <section>
            <h2 class="{{ $h2 }}">7. How to test visually</h2>
            <ol class="space-y-2 pl-1">
                <li class="{{ $li }}"><strong>1.</strong> Pick a connected (GSC) website. Run <span class="{{ $code }}">php artisan ebq:crawl-websites --website=&lt;id&gt; --force</span>; watch a <span class="{{ $code }}">crawl_runs</span> row go <em>running → completed</em> and <span class="{{ $code }}">website_pages</span> fill up. (Needs a queue worker for the batch.)</li>
                <li class="{{ $li }}"><strong>2.</strong> Open the <strong>Dashboard</strong>: the <strong>Site Health</strong> widget shows score, pages, open issues, and orphan count (and a red banner if the crawler is blocked).</li>
                <li class="{{ $li }}"><strong>3.</strong> On the dashboard <strong>Priority Action Queue</strong>: crawl groups (broken links, orphans, on-page, indexability) appear severity-ranked; click one — the slide-over lists the affected URLs and the Fix link deep-links into the <strong>Link Structure</strong> page for that URL.</li>
                <li class="{{ $li }}"><strong>3b.</strong> Open <strong>Link Structure</strong> (left nav), type/paste a page URL → see what links to it, what it links to (broken targets flagged), and AI-suggested links.</li>
                <li class="{{ $li }}"><strong>4.</strong> Seed a known issue: point a page at a dead internal URL and add <span class="{{ $code }}">&lt;meta name="robots" content="noindex"&gt;</span> on a traffic page → recrawl → confirm a <span class="{{ $code }}">broken_internal</span> and a <span class="{{ $code }}">noindex_important</span> finding appear.</li>
                <li class="{{ $li }}"><strong>5.</strong> Recrawl-optimization: run <span class="{{ $code }}">--force</span> twice; the 2nd run's <span class="{{ $code }}">pages_304</span> should be high and <span class="{{ $code }}">pages_changed</span> low. Edit a page → it flips to <em>changed</em> and re-runs findings.</li>
                <li class="{{ $li }}"><strong>6.</strong> Backfill: an existing never-crawled site picked up by <span class="{{ $code }}">--backfill</span> (or the weekly tick) gets its first <span class="{{ $code }}">crawl_runs</span> row and inventory.</li>
                <li class="{{ $li }}"><strong>7.</strong> Blocking: a site behind a CAPTCHA/403 → run marks <em>aborted</em>, a single crawlability finding, and the red "can't crawl" banner on the dashboard Site Health widget (no false page-level noise).</li>
            </ol>
        </section>
    </div>
</x-layouts.app>
