@php
    $free          = (bool) config('app.free');
    // CTAs land logged-out users on /register?plan=X (where store()
    // bounces them to /billing/checkout right after auth) and existing
    // logged-in users straight onto /billing/checkout?plan=X. Either way
    // payment happens before onboarding.
    $authed        = auth()->check();
    $ctaForPlan    = function (string $slug, string $interval = 'annual') use ($authed) {
        $params = array_filter(['plan' => $slug, 'interval' => $interval !== 'annual' ? $interval : null]);
        return $authed
            ? route('billing.checkout', $params)
            : route('register', $params);
    };
    $registerUrl   = route('register');
    $featuresUrl   = route('features');
    $contactUrl    = route('contact');
    $refundUrl     = route('refund-policy');

    $heroEyebrow   = $free ? 'Limited-time promotion' : 'Pricing';
    $heroTitle     = $free
        ? 'Free for a limited time, then from $19/month.'
        : 'Pay for the sites you manage. Nothing else.';
    $heroSub       = $free
        ? 'Every account currently gets full Pro capabilities at no cost during this promotional period. When the promotion ends, plans start at just $19/month — and we will let you know well before anything changes.'
        : 'Every plan includes the Serfix workspace, WordPress plugin, and team access. Billed annually — start on the free Trial plan, upgrade when you\'re ready.';
    $heroBadge     = $free
        ? 'Free now · then from $19/month'
        : 'Free Trial plan · No card required';

    // Pull plans straight from the DB — `Admin\PlanController` keeps
    // pricing, feature toggles, and quotas in sync without a deploy.
    // The Plan model + seeder ship the canonical 5-tier set; the
    // `Plan::ordered()` scope filters to active plans in display_order.
    $planRows = \App\Models\Plan::ordered()->get();

    // Display copy for the 8 plugin features. The pricing card's
    // "Includes:" list is auto-generated from `featureMap()` — every
    // checked flag emits its label here. Keys match Plan::FEATURE_KEYS.
    $featureCopy = [
        'live_audit'       => 'Live SEO score & audit',
        'hq'               => 'Serfix HQ rank tracker + performance',
        'redirects'        => '404-monitor + redirects manager',
        'dashboard_widget' => 'WordPress dashboard widget',
        'post_column'      => 'Posts-list Serfix score column',
        'ai_inline'        => 'AI inline edits (// slash commands)',
        'chatbot'          => 'Serfix Assistant chatbot',
        'ai_writer'        => 'AI Writer (full-draft generation)',
    ];

    // Pretty-format the per-plan API caps into 1-line strings.
    $apiLimitCopy = function (?array $limits): array {
        if (! is_array($limits)) {
            return [];
        }
        $out = [];
        $rt  = $limits['rank_tracker']['max_active_keywords'] ?? null;
        if ($rt !== null) {
            $out[] = number_format((int) $rt) . ' tracked keywords';
        }
        $kr  = $limits['keyword_research']['monthly_searches'] ?? null;
        if ($kr !== null) {
            $out[] = number_format((int) $kr) . ' keyword research searches / month';
        }
        $ast = $limits['ai_studio']['monthly_tokens'] ?? null;
        if ($ast !== null) {
            $out[] = number_format((int) $ast) . ' AI Studio tokens / month';
        }
        $lf  = $limits['long_form']['monthly_articles'] ?? null;
        if ($lf !== null) {
            $out[] = number_format((int) $lf) . ' long-form articles / month';
        }
        $qw  = $limits['quick_win_finder']['results_shown'] ?? null;
        if ($qw !== null) {
            $out[] = 'Quick Win Finder: ' . number_format((int) $qw) . ' results';
        }
        return $out;
    };

    // CTA label honours each plan's stored `trial_days`.
    $trialCtaLabel = function (int $trialDays): string {
        if ($trialDays <= 0) {
            return 'Get started';
        }
        if ($trialDays % 30 === 0) {
            $months = intdiv($trialDays, 30);
            return $months === 1 ? 'Start 1-month trial' : "Start {$months}-month trial";
        }
        return "Start {$trialDays}-day trial";
    };
    $planStyleFor = function (string $slug, int $trialDays) use ($trialCtaLabel): array {
        return match ($slug) {
            'trial'      => ['cta_label' => 'Start free',  'cta_style' => 'ghost'],
            'enterprise' => ['cta_label' => 'Contact us',  'cta_style' => 'ghost'],
            default      => ['cta_label' => $trialCtaLabel($trialDays), 'cta_style' => 'primary'],
        };
    };

    $websitesBullet = function (\App\Models\Plan $p): string {
        if ($p->max_websites === null) {
            return 'Unlimited connected websites';
        }
        return $p->max_websites === 1
            ? '1 connected website'
            : (int) $p->max_websites . ' connected websites';
    };

    $plans = $planRows->map(function (\App\Models\Plan $p) use ($ctaForPlan, $registerUrl, $contactUrl, $featureCopy, $apiLimitCopy, $planStyleFor, $websitesBullet) {
        $slug    = (string) $p->slug;
        $monthly = (int) $p->price_monthly_usd;
        $yearly  = (int) $p->price_yearly_usd;
        // Annual monthly-equivalent (e.g. $168/yr ÷ 12 = $14/mo for Solo)
        $annualMonthly = ($yearly > 0) ? (int) round($yearly / 12) : 0;
        $style   = $planStyleFor($slug, (int) $p->trial_days);

        $featureMap  = $p->featureMap();
        $autoBullets = [];
        $autoBullets[] = $websitesBullet($p);
        if ($p->max_seats !== null) {
            $autoBullets[] = $p->max_seats === 1 ? '1 team seat' : $p->max_seats . ' team seats';
        } else {
            $autoBullets[] = 'Unlimited team seats';
        }
        $autoBullets = array_merge($autoBullets, $apiLimitCopy($p->api_limits));
        foreach ($featureCopy as $key => $label) {
            if (($featureMap[$key] ?? false) === true) {
                $autoBullets[] = $label;
            }
        }

        $excluded = [];
        foreach ($featureCopy as $key => $label) {
            if (($featureMap[$key] ?? false) === false) {
                $excluded[] = $label;
            }
        }

        $rawBullets   = is_array($p->features) ? array_values($p->features) : [];
        $bulletVideos = is_array($p->feature_videos ?? null) ? $p->feature_videos : [];
        $featureItems = [];
        foreach ($rawBullets as $i => $bullet) {
            $videoUrl = $bulletVideos[(string) $i] ?? ($bulletVideos[$i] ?? null);
            $featureItems[] = [
                'text'     => $bullet,
                'video_id' => \App\Models\Plan::youtubeId($videoUrl),
            ];
        }

        $ctaUrl        = match ($slug) {
            'trial'      => $registerUrl,
            'enterprise' => $contactUrl,
            default      => $ctaForPlan($slug, 'annual'),
        };
        $ctaUrlMonthly = match ($slug) {
            'trial'      => $registerUrl,
            'enterprise' => $contactUrl,
            default      => $ctaForPlan($slug, 'monthly'),
        };

        $priceMonthly = match (true) {
            $slug === 'enterprise'  => 'Custom',
            $monthly > 0            => '$' . number_format($monthly),
            default                 => '$0',
        };
        $priceAnnual = match (true) {
            $slug === 'enterprise'  => 'Custom',
            $slug === 'trial'       => '$0',
            $annualMonthly > 0      => '$' . number_format($annualMonthly),
            default                 => '$0',
        };
        $suffix = match (true) {
            $slug === 'enterprise'  => '',
            default                 => '/mo',
        };
        $captionAnnual = match (true) {
            $slug === 'enterprise'  => 'Contact us for pricing.',
            $slug === 'trial'       => 'Free forever. No card required.',
            $yearly > 0             => '$' . number_format($yearly) . ' billed yearly',
            default                 => 'No card required.',
        };
        $captionMonthly = match (true) {
            $slug === 'enterprise'  => 'Contact us for pricing.',
            $slug === 'trial'       => 'Free forever. No card required.',
            $monthly > 0            => 'Billed monthly.',
            default                 => 'No card required.',
        };
        $savingsPct = ($monthly > 0 && $annualMonthly > 0)
            ? (int) round(($monthly - $annualMonthly) / $monthly * 100)
            : 0;

        return [
            'slug'            => $slug,
            'name'            => (string) $p->name,
            'price'           => $priceMonthly,
            'price_monthly'   => $priceMonthly,
            'price_annual'    => $priceAnnual,
            'suffix'          => $suffix,
            'caption'         => $captionAnnual,
            'caption_annual'  => $captionAnnual,
            'caption_monthly' => $captionMonthly,
            'savings_pct'     => $savingsPct,
            'tagline'         => (string) ($p->tagline ?? ''),
            'features'        => $featureItems,
            'includes'        => $autoBullets,
            'excluded'        => $excluded,
            'cta_label'        => $style['cta_label'],
            'cta_url'          => $ctaUrl,
            'cta_url_monthly'  => $ctaUrlMonthly,
            'cta_style'        => $style['cta_style'],
            'highlight'       => (bool) $p->is_highlighted,
        ];
    })->all();

    $faqs = [
        ['Is there a free plan?',            'Yes — the Trial plan is free forever with no credit card required. It gives you 1 website, 20 tracked keywords, and up to 20,000 crawled pages. Upgrade to a paid plan whenever you\'re ready.'],
        ['What is the difference between monthly and annual pricing?', 'The "annual" column shows the per-month equivalent when you pay as a single yearly charge — Solo works out to $14/mo ($168/year), Pro to $37/mo ($444/year), and Agency to $74/mo ($888/year), saving up to 26% vs month-to-month.'],
        ['Can I switch plans later?',        'Yes. Upgrades pro-rate immediately for the rest of your annual term. Downgrades take effect at the next renewal so you keep what you paid for.'],
        ['What counts as a website?',        'A unique domain or subdomain you connect to Serfix. Each gets its own GSC sync, audit history, keyword tracker, and dashboard.'],
        ['Do you offer refunds?',            'Yes — see our refund policy for the 30-day money-back terms.'],
        ['Which payment methods do you accept?', 'All major credit and debit cards via our PCI-compliant payment processor. Invoicing is available on Agency and Enterprise plans.'],
        ['Do prices include tax?',           'Prices shown exclude applicable VAT/GST. Local taxes are calculated at checkout based on your billing country.'],
        ['What is the Enterprise plan?',     'Enterprise is a custom plan for large organisations with specific requirements (SSO, dedicated support, custom data retention, invoicing). Contact us and we\'ll put together a package that fits.'],
    ];

    $trustItems = [
        ['title' => '30-day money-back', 'sub' => 'Full refund if Serfix is not a fit.'],
        ['title' => 'Cancel anytime',    'sub' => 'No long-term contracts.'],
        ['title' => 'Secure billing',    'sub' => 'PCI-compliant card processor.'],
        ['title' => 'GDPR & SOC 2-aligned', 'sub' => 'Privacy-first data handling.'],
    ];

    // Feature comparison table.
    // Values: true = check mark, false = cross, string = literal cell value.
    $compareTable = [
        'Workspace' => [
            ['feature' => 'Projects (websites)',      'trial' => '1',        'solo' => '3',        'pro' => '10',       'agency' => '30',        'enterprise' => 'Custom'],
            ['feature' => 'Team seats',               'trial' => '1',        'solo' => '1',        'pro' => '3',        'agency' => '10',        'enterprise' => 'Custom'],
            ['feature' => 'Extra seat',               'trial' => '—',        'solo' => '$10/mo',   'pro' => '$10/mo',   'agency' => '$8/mo',     'enterprise' => 'Custom'],
        ],
        'Crawl & Audit' => [
            ['feature' => 'Monthly crawl budget',     'trial' => '20,000',   'solo' => '100,000',  'pro' => '300,000',  'agency' => '1,000,000', 'enterprise' => 'Custom'],
            ['feature' => 'Detailed site audits',     'trial' => true,       'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
            ['feature' => 'Bilingual audit (AR/EN)',  'trial' => true,       'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
        ],
        'Rank Tracking' => [
            ['feature' => 'Tracked keywords',         'trial' => '20',       'solo' => '100',      'pro' => '500',      'agency' => '2,000',     'enterprise' => 'Custom'],
        ],
        'Keyword Research' => [
            ['feature' => 'Searches / month',         'trial' => '50',       'solo' => '250',      'pro' => '1,000',    'agency' => '4,000',     'enterprise' => 'Custom'],
            ['feature' => 'Results per search',       'trial' => '1,000',    'solo' => '5,000',    'pro' => '10,000',   'agency' => '30,000',    'enterprise' => 'Custom'],
            ['feature' => 'Competitor analysis',      'trial' => 'Shared',   'solo' => 'Shared',   'pro' => 'Shared',   'agency' => 'Shared',    'enterprise' => 'Custom'],
            ['feature' => 'Arabic keyword support',   'trial' => true,       'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
        ],
        'Backlinks & SERP' => [
            ['feature' => 'Backlink analysis',        'trial' => true,       'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
            ['feature' => 'Orphan link detection',    'trial' => true,       'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
        ],
        'AI & Content' => [
            ['feature' => 'AI Studio (tokens / mo)',  'trial' => '25,000',   'solo' => '60,000',   'pro' => '150,000',  'agency' => '600,000',   'enterprise' => 'Custom'],
            ['feature' => 'Long Form articles / mo',  'trial' => '2',        'solo' => '5',        'pro' => '15',       'agency' => '50',        'enterprise' => 'Custom'],
            ['feature' => 'Quick Win Finder results', 'trial' => '5',        'solo' => '10',       'pro' => '20',       'agency' => '30',        'enterprise' => 'Custom'],
        ],
        'Insights & Reporting' => [
            ['feature' => 'Action insights (GSC + GA4)', 'trial' => true,    'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
            ['feature' => 'Scheduled reports',        'trial' => false,      'solo' => false,      'pro' => true,       'agency' => true,        'enterprise' => true],
            ['feature' => 'White-label reports',      'trial' => false,      'solo' => false,      'pro' => false,      'agency' => true,        'enterprise' => true],
        ],
        'WordPress Plugin' => [
            ['feature' => 'WordPress plugin',         'trial' => true,       'solo' => true,       'pro' => true,       'agency' => true,        'enterprise' => true],
        ],
    ];

    $jsonLd = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Product',
        'name'          => 'Serfix',
        'description'   => 'Serfix is an SEO operations platform combining rankings, audits, backlinks, and AI content tools.',
        'brand'         => ['@type' => 'Brand', 'name' => 'Serfix'],
        'offers'        => [
            '@type'         => 'AggregateOffer',
            'priceCurrency' => 'USD',
            'lowPrice'      => (string) ($planRows->min('price_monthly_usd') ?? 0),
            'highPrice'     => (string) ($planRows->max('price_monthly_usd') ?? 0),
            'offerCount'    => count($plans),
            'availability'  => 'https://schema.org/InStock',
            'url'           => url()->current(),
        ],
    ];

    // FAQPage from the visible Q&As below, plus a breadcrumb trail.
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
        ], $faqs),
    ];
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('landing')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Pricing', 'item' => route('pricing')],
        ],
    ];
    $pricingJsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
@endphp

<x-marketing.page
    title="Serfix Pricing | Simple Plans for Advanced AI & SEO Audits"
    description="View Serfix pricing plans. Scale your traffic with a 47-tool AI studio, automated rank tracking, and deep multi-site SEO audits. Try it risk-free today!"
    active="pricing"
>
    {{-- Page-specific structured data: product offers, FAQ, breadcrumb. --}}
    <x-slot:schema>
        <script type="application/ld+json">{!! json_encode($jsonLd, $pricingJsonFlags) !!}</script>
        <script type="application/ld+json">{!! json_encode($faqSchema, $pricingJsonFlags) !!}</script>
        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, $pricingJsonFlags) !!}</script>
    </x-slot:schema>

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 text-center lg:px-8 lg:py-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] {{ $free ? 'text-emerald-700' : 'text-slate-500' }}">{{ $heroEyebrow }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                {{ $heroTitle }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ $heroSub }}
            </p>
            <div class="mt-7 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-medium {{ $free ? 'text-emerald-700' : 'text-slate-600' }}">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                {{ $heroBadge }}
            </div>

            @if (! $free)
                <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ $registerUrl }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">Start free</a>
                    <a href="#plans" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">Compare plans</a>
                </div>
            @endif
        </div>
    </section>

    @if ($free)
        {{-- ── Free promo strip (compact, keeps the table high) ──── --}}
        <section class="border-b border-emerald-200 bg-emerald-50/60">
            <div class="mx-auto max-w-4xl px-6 py-5 text-center lg:px-8">
                <p class="text-sm font-semibold text-emerald-800">
                    Free for a limited time — then from&nbsp;$19/month.
                    <span class="font-normal text-emerald-700">Every paid feature is unlocked now at no cost; we'll give 30 days' notice before pricing starts.</span>
                </p>
            </div>
        </section>
    @endif

    {{-- ── Billing toggle + plan cards ─────────────────────────── --}}
    <div x-data="{ billing: 'annual' }">

        {{-- Toggle --}}
        <div class="bg-white pt-10 pb-4 text-center">
            <div class="inline-flex rounded-full border border-slate-200 bg-slate-50/80 p-1 shadow-sm">
                <button
                    @click="billing = 'monthly'"
                    :class="billing === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                    class="rounded-full px-5 py-1.5 text-sm font-semibold transition">
                    Monthly
                </button>
                <button
                    @click="billing = 'annual'"
                    :class="billing === 'annual' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                    class="inline-flex items-center gap-2 rounded-full px-5 py-1.5 text-sm font-semibold transition">
                    Annual
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700">Save 26%</span>
                </button>
            </div>
        </div>

        {{-- ── Plan cards ───────────────────────────────────────── --}}
        <section id="plans" class="bg-white pb-16 pt-6 sm:pt-8">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    @foreach ($plans as $plan)
                        <div @class([
                            'relative flex flex-col rounded-2xl border bg-white p-6',
                            'border-slate-900 shadow-[0_24px_60px_-24px_rgba(15,23,42,0.25)]' => $plan['highlight'],
                            'border-slate-200' => ! $plan['highlight'],
                        ])>
                            @if ($plan['highlight'])
                                <span class="absolute -top-3 left-6 inline-flex items-center rounded-full bg-slate-900 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-white">Most popular</span>
                            @endif

                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $plan['name'] }}</p>

                            <div class="mt-4 flex items-baseline gap-1.5">
                                {{-- Annual price (default) --}}
                                <span class="text-4xl font-semibold tracking-tight text-slate-900"
                                      x-show="billing === 'annual'">{{ $plan['price_annual'] }}</span>
                                {{-- Monthly price --}}
                                <span class="text-4xl font-semibold tracking-tight text-slate-900"
                                      x-show="billing === 'monthly'" style="display:none">{{ $plan['price_monthly'] }}</span>
                                <span class="text-sm text-slate-500">{{ $plan['suffix'] }}</span>
                            </div>

                            <p class="mt-1 text-xs text-slate-500"
                               x-show="billing === 'annual'">{{ $plan['caption_annual'] }}</p>
                            <p class="mt-1 text-xs text-slate-500"
                               x-show="billing === 'monthly'" style="display:none">{{ $plan['caption_monthly'] }}</p>

                            <p class="mt-4 text-sm text-slate-600">{{ $plan['tagline'] }}</p>

                            <ul class="mt-6 space-y-2.5 text-[13px] text-slate-700">
                                {{-- Hand-written marketing bullets. A bullet
                                     with a YouTube link in admin renders a
                                     prominent red play badge that opens the
                                     auto-playing video modal. --}}
                                @foreach ($plan['features'] as $feature)
                                    <li class="flex gap-2.5">
                                        <svg class="mt-0.5 h-4 w-4 flex-none text-slate-900" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        @if ($feature['video_id'])
                                            <button type="button"
                                                    data-ebq-video="{{ $feature['video_id'] }}"
                                                    aria-haspopup="dialog"
                                                    class="group/vid inline-flex items-center gap-1.5 text-left font-medium text-slate-800 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-1">
                                                <span class="underline decoration-dotted decoration-slate-300 underline-offset-2 group-hover/vid:decoration-slate-500">{{ $feature['text'] }}</span>
                                                <span class="inline-flex h-5 w-5 flex-none items-center justify-center rounded-full bg-red-600 text-white shadow-sm transition group-hover/vid:bg-red-700 group-hover/vid:scale-110">
                                                    <svg class="h-3 w-3 translate-x-[1px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
                                                </span>
                                                <span class="sr-only">— play video</span>
                                            </button>
                                        @else
                                            <span>{{ $feature['text'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <a data-url-annual="{{ $plan['cta_url'] }}"
                               data-url-monthly="{{ $plan['cta_url_monthly'] }}"
                               :href="billing === 'monthly' ? $el.dataset.urlMonthly : $el.dataset.urlAnnual"
                               aria-label="{{ $plan['cta_label'] }} — {{ $plan['name'] }} plan"
                               @class([
                                'mt-7 inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                                'bg-slate-900 text-white hover:bg-slate-800 focus-visible:ring-slate-900' => $plan['cta_style'] === 'primary',
                                'border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-900 focus-visible:ring-slate-300' => $plan['cta_style'] === 'ghost',
                            ])>
                                {{ $plan['cta_label'] }}
                            </a>
                        </div>
                    @endforeach
                </div>

                <p class="mt-8 text-center text-xs text-slate-500">
                    Prices in USD. Annual billing saves up to 26%. Local taxes (VAT/GST) calculated at checkout.
                    Need monthly billing for procurement?
                    <a href="{{ $contactUrl }}" class="font-medium text-slate-700 underline-offset-2 hover:text-slate-900 hover:underline">Get in touch</a>.
                </p>
            </div>
        </section>

    {{-- ── Trust strip ──────────────────────────────────────────── --}}
    <section class="border-y border-slate-200 bg-slate-50/60 py-10">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($trustItems as $item)
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-7 w-7 flex-none items-center justify-center rounded-full bg-white ring-1 ring-slate-200">
                            <svg class="h-3.5 w-3.5 text-slate-900" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{ $item['title'] }}</p>
                            <p class="mt-0.5 text-xs text-slate-600">{{ $item['sub'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ── Feature comparison table ─────────────────────────────── --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mb-10 text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Full Comparison</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Everything, side by side.</h2>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="bg-slate-50">
                            <th scope="col" class="py-3.5 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 w-52">Feature</th>
                            @foreach ($plans as $plan)
                                <th scope="col" @class([
                                    'py-3.5 px-4 text-center text-xs font-semibold uppercase tracking-[0.12em]',
                                    'bg-slate-100 text-slate-900' => $plan['highlight'],
                                    'text-slate-500' => ! $plan['highlight'],
                                ])>
                                    {{ $plan['name'] }}
                                    @if ($plan['highlight'])
                                        <span class="ml-1 text-slate-400">★</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                        {{-- Pricing summary row inside the table --}}
                        <tr class="border-t border-slate-200 bg-white">
                            <td class="py-3 pl-6 pr-4 text-xs font-medium text-slate-500">Annual / mo</td>
                            @foreach ($plans as $plan)
                                <td @class([
                                    'py-3 px-4 text-center text-xs font-semibold',
                                    'bg-slate-50' => $plan['highlight'],
                                    'text-slate-900' => true,
                                ])>{{ $plan['price_annual'] }}</td>
                            @endforeach
                        </tr>
                        <tr class="border-t border-slate-100 bg-white">
                            <td class="py-3 pl-6 pr-4 text-xs font-medium text-slate-500">Monthly / mo</td>
                            @foreach ($plans as $plan)
                                <td @class([
                                    'py-3 px-4 text-center text-xs text-slate-500',
                                    'bg-slate-50' => $plan['highlight'],
                                ])>{{ $plan['price_monthly'] }}</td>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($compareTable as $group => $rows)
                            {{-- Group header row --}}
                            <tr class="bg-slate-50/70">
                                <td colspan="{{ count($plans) + 1 }}" class="py-2.5 pl-6 pr-4 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">{{ $group }}</td>
                            </tr>
                            @foreach ($rows as $row)
                                <tr class="transition-colors hover:bg-slate-50/50">
                                    <td class="py-3 pl-6 pr-4 font-medium text-slate-700">{{ $row['feature'] }}</td>
                                    @foreach (['trial', 'solo', 'pro', 'agency', 'enterprise'] as $planSlug)
                                        @php $planObj = collect($plans)->firstWhere('slug', $planSlug); @endphp
                                        <td @class([
                                            'py-3 px-4 text-center',
                                            'bg-slate-50/80' => ($planObj['highlight'] ?? false),
                                        ])>
                                            @if ($row[$planSlug] === true)
                                                <svg class="mx-auto h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-label="Included"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                            @elseif ($row[$planSlug] === false)
                                                <svg class="mx-auto h-4 w-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-label="Not included"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            @else
                                                <span class="text-[13px] text-slate-700">{{ $row[$planSlug] }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    {{-- Footer CTA row --}}
                    <tfoot>
                        <tr class="border-t border-slate-200 bg-slate-50">
                            <td class="py-4 pl-6 pr-4 text-xs text-slate-500">All prices USD, annual billing.</td>
                            @foreach ($plans as $plan)
                                <td @class([
                                    'py-4 px-4 text-center',
                                    'bg-slate-100/80' => $plan['highlight'],
                                ])>
                                    <a data-url-annual="{{ $plan['cta_url'] }}"
                                       data-url-monthly="{{ $plan['cta_url_monthly'] }}"
                                       :href="billing === 'monthly' ? $el.dataset.urlMonthly : $el.dataset.urlAnnual"
                                       class="inline-flex items-center justify-center rounded-lg px-3 py-1.5 text-xs font-semibold transition focus:outline-none {{ $plan['cta_style'] === 'primary' ? 'bg-slate-900 text-white hover:bg-slate-800' : 'border border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">
                                        {{ $plan['cta_label'] }}
                                    </a>
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>

    </div>{{-- end billing x-data wrapper (toggle + cards + comparison table) --}}

    {{-- ── Pricing FAQ ──────────────────────────────────────────── --}}
    <section class="bg-slate-50/60 py-16 sm:py-20">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">FAQ</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Pricing questions, answered.</h2>
            </div>

            <div class="mt-10 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ($faqs as [$question, $answer])
                    <details class="group p-6 [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex cursor-pointer items-center justify-between gap-3 text-[15px] font-semibold text-slate-900">
                            <span>{{ $question }}</span>
                            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-slate-100 text-slate-600 transition group-open:rotate-45 group-open:bg-slate-900 group-open:text-white">
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            </span>
                        </summary>
                        <p class="mt-3 text-[14px] leading-7 text-slate-600">
                            {{ $answer }}
                            @if (str_contains(strtolower($question), 'refund'))
                                <a href="{{ $refundUrl }}" class="font-medium text-slate-700 underline-offset-2 hover:text-slate-900 hover:underline">Read the refund policy</a>.
                            @endif
                        </p>
                    </details>
                @endforeach
            </div>

            <p class="mt-8 text-center text-sm text-slate-600">
                Still have a question?
                <a href="{{ $contactUrl }}" class="font-semibold text-slate-900 underline-offset-2 hover:underline">Contact us</a>
                — we usually reply the same business day.
            </p>
        </div>
    </section>

    {{-- ── CTA ──────────────────────────────────────────────── --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-4xl px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-slate-50/60 px-6 py-14 text-center sm:px-12">
                <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                    {{ $free ? 'Claim your free Pro access today.' : 'Ready to ship better SEO?' }}
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">
                    {{ $free
                        ? 'Sign up in under two minutes and get every Pro feature unlocked while the promotion lasts.'
                        : 'Connect your first website in under two minutes. Start free on the Trial plan — no card required.' }}
                </p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ $registerUrl }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">{{ $free ? 'Start free Pro access' : 'Start free' }}</a>
                    <a href="{{ $featuresUrl }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2">See features</a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Bullet explainer-video modal ─────────────────────────
         Single shared dialog reused by every "play video" bullet. The
         iframe src is only set on open (with autoplay=1) and cleared on
         close so audio stops the moment the modal is dismissed. --}}
    <div id="ebq-video-modal"
         class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/80 p-4 backdrop-blur-sm"
         role="dialog" aria-modal="true" aria-label="Feature video">
        <div class="relative w-full max-w-3xl">
            <button type="button" id="ebq-video-close"
                    class="absolute -top-9 right-0 inline-flex items-center gap-1 text-sm font-medium text-white/90 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                    aria-label="Close video">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                Close
            </button>
            <div class="aspect-video w-full overflow-hidden rounded-xl bg-black shadow-2xl ring-1 ring-white/10">
                <iframe id="ebq-video-frame" class="h-full w-full" src="" title="Feature video"
                        loading="lazy" referrerpolicy="strict-origin-when-cross-origin"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        allowfullscreen></iframe>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('ebq-video-modal');
            var frame = document.getElementById('ebq-video-frame');
            var closeBtn = document.getElementById('ebq-video-close');
            if (!modal || !frame || !closeBtn) {
                return;
            }

            function openVideo(id) {
                if (!/^[A-Za-z0-9_-]{11}$/.test(id)) {
                    return;
                }
                frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.style.overflow = 'hidden';
                closeBtn.focus();
            }

            function closeVideo() {
                frame.src = '';
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = '';
            }

            document.querySelectorAll('[data-ebq-video]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openVideo(btn.getAttribute('data-ebq-video'));
                });
            });

            closeBtn.addEventListener('click', closeVideo);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeVideo();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeVideo();
                }
            });
        })();
    </script>
</x-marketing.page>
