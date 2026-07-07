<x-marketing.page
    active="wordpress"
    title="Serfix SEO – WordPress SEO Plugin with Actionable Audits"
    description="Supercharge your rankings. Get the best WordPress SEO plugin with actionable audits, live GSC insights, automated rank tracking, and a 47-tool AI studio."
>
    {{-- Page-specific structured data: the plugin itself + breadcrumb. --}}
    <x-slot:schema>
        @php
            $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
            $appSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => 'Serfix SEO',
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'WordPress',
                'url' => route('wordpress-plugin'),
                'downloadUrl' => route('wordpress.plugin.download'),
                'softwareVersion' => '1.0.5',
                'description' => 'WordPress SEO plugin with actionable audits, live Google Search Console insights, automated rank tracking, schema, sitemaps, redirects, and a 47-tool AI studio.',
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
                'publisher' => ['@type' => 'Organization', 'name' => 'Serfix', 'url' => route('landing')],
            ];
            $breadcrumbSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('landing')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'WordPress SEO Plugin', 'item' => route('wordpress-plugin')],
                ],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($appSchema, $jsonFlags) !!}</script>
        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, $jsonFlags) !!}</script>
    </x-slot:schema>

    @php
        $downloadUrl = route('wordpress.plugin.download');

        // Feature groups rendered as cards. Each: heading + bullet list.
        $groups = [
            [
                'title' => __('In the editor'),
                'desc'  => __('Everything where you write — Gutenberg and Classic.'),
                'items' => [
                    __('Focus keyphrase scoring with worst-bullet-wins verdict (Excellent / Good / Needs work)'),
                    __('SEO title + meta description editor with live SERP preview (desktop + mobile)'),
                    'Title variables: %%title%%, %%sep%%, %%sitename%%',
                    __('Open Graph + Twitter card overrides per post, with featured-image fallback'),
                    __('Canonical URL, robots noindex/nofollow, and slug — all in one panel'),
                    __('Inline AI block toolbar: rewrite, expand, shorten, fix grammar, change tone, translate'),
                ],
            ],
            [
                'title' => __('On-page output'),
                'desc'  => __('Clean, correct markup emitted on the front end.'),
                'items' => [
                    __('Meta title + description, Open Graph and Twitter cards, canonical, robots'),
                    __('JSON-LD schema: Article, BlogPosting, NewsArticle, Product, Recipe, HowTo, FAQ, Event, and more'),
                    __('XML sitemap at /ebq-sitemap.xml (posts, pages, custom post types, taxonomies)'),
                    __('Breadcrumb shortcode + JSON-LD BreadcrumbList'),
                    __('Hreflang tags for multilingual sites'),
                ],
            ],
            [
                'title' => __('Live insights from your workspace'),
                'desc'  => __('Real Search Console + audit data, not heuristics.'),
                'items' => [
                    __('Live SEO score combining Search Console, Lighthouse, and on-page audit signals'),
                    __('Per-post GSC performance: 30-day and 90-day clicks, impressions, position, CTR'),
                    __('Top queries actually driving traffic to each page'),
                    __('Cannibalization detection and striking-distance opportunity flags'),
                    __('Rank tracking with daily position snapshots and movement deltas'),
                    __('Core Web Vitals + page audit deep-links'),
                ],
            ],
            [
                'title' => __('AI writing assistant'),
                'desc'  => __('The full Serfix AI surface, inside WordPress.'),
                'items' => [
                    __('Rank Assist — a floating SEO copilot with one-click structured actions'),
                    __('AI Studio — 47 tools for research, writing, improvement, marketing, eCommerce, media'),
                    __('AI Writer — full-post drafts from a focus keyphrase + brief'),
                    __('Brand voice — train every tool on your house style'),
                ],
            ],
            [
                'title' => __('Redirects & 404 tracking'),
                'desc'  => __('Keep link equity and catch broken URLs.'),
                'items' => [
                    __('301/302 redirect manager'),
                    __('404 tracker that logs misses and suggests automated redirects'),
                    __('Import redirects from existing plugins'),
                ],
            ],
            [
                'title' => __('Serfix HQ + operations'),
                'desc'  => __('A full admin dashboard and hands-off upkeep.'),
                'items' => [
                    __('Serfix HQ admin: overview, performance, keywords, index status, insights, growth report, page audits'),
                    __('One-click connect to your Serfix workspace — no codes or tokens to copy'),
                    __('Per-site feature toggles controlled from your Serfix admin'),
                    __('Migrate from Yoast or RankMath (titles, descriptions, schema, redirects)'),
                    __('Self-update from Serfix HQ and a built-in 24-language UI'),
                ],
            ],
        ];
    @endphp

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="relative">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[26rem] bg-[radial-gradient(ellipse_at_top,rgba(99,102,241,0.08),transparent_60%)]"></div>

        <div class="mx-auto max-w-3xl px-6 pb-16 pt-16 text-center lg:px-8 lg:pt-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('WordPress plugin') }}</p>
            <h1 class="mt-4 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                {{ __('Serfix SEO for WordPress') }}
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('A connected SEO suite that pairs your WordPress editor with your Serfix workspace — real-data keyword scoring, live insights, rank tracking, schema, sitemaps, redirects, and an AI writing assistant.') }}
            </p>

            <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ $downloadUrl }}"
                   class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    {{ __('Download plugin') }}
                </a>
                <a href="{{ route('guide') }}#step-8"
                   class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                    {{ __('How to install') }}
                </a>
            </div>

            <p class="mt-5 text-xs text-slate-500">{{ __('Latest packaged build · GPL-2.0 · Upload via Plugins → Add New → Upload, then Connect to Serfix') }}</p>
        </div>
    </section>

    {{-- ── Feature groups ────────────────────────────────────── --}}
    <section class="bg-white pb-8">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($groups as $group)
                    <div class="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-slate-900">{{ $group['title'] }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $group['desc'] }}</p>
                        <ul class="mt-4 space-y-2.5 text-[13px] leading-6 text-slate-700">
                            @foreach ($group['items'] as $item)
                                <li class="flex gap-2.5">
                                    <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Bottom CTA ────────────────────────────────────────── --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-4xl px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-slate-50/60 px-8 py-12 text-center">
                <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900">{{ __('Install in under a minute') }}</h2>
                <p class="mx-auto mt-3 max-w-2xl text-[15px] leading-7 text-slate-600">
                    {{ __('Download the plugin, upload it to your site, activate, and click') }} <span class="font-semibold">{{ __('Connect to Serfix') }}</span>. {{ __('Core SEO (sitemap, schema, meta, redirects) works immediately; live data and AI unlock when you connect.') }}
                </p>
                <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ $downloadUrl }}"
                       class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        {{ __('Download plugin') }}
                    </a>
                    <a href="{{ route('register') }}"
                       class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                        {{ __('Create your Serfix account') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-marketing.page>
