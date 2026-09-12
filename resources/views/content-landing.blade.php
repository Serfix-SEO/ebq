@php
    /**
     * Content AI Autopilot — the public landing page.
     *
     * Rebuilt 2026-09-12 to the owner's design: hero → four-step strip → dark
     * product tour → journey timeline → content quality → real results →
     * publishing + less work → FAQ → pricing → blog → closing CTA. Each
     * section is its own partial under partials/content-landing/ so a copy
     * tweak touches one named file instead of a 900-line page; Blade @include
     * inherits this scope, so the data below reaches every section unchanged.
     *
     * Rules this page must keep:
     *  - Every price/limit comes from ContentAutopilotConfig, never hardcoded —
     *    the landing page, the pricing page and the in-app screens read one
     *    source and can never drift.
     *  - Client-facing copy only: outcomes, never our data vendors, model names
     *    or internal pipeline detail (pinned by PricingPagesTest).
     *  - Product visuals are REAL product UI rendered against invented sample
     *    data (see tests/Feature/Content/MarketingShotsTest.php) — no client's
     *    content, and no mocked-up screens that the product cannot produce.
     *    The design mockup's proof numbers were placeholder art; the real
     *    Search Console figures in results.blade.php are what ship.
     *  - Statistics are attributed to their published source. No invented
     *    percentages.
     *  - ONE posting form on the page (the hero). reCAPTCHA is live in prod and
     *    a second widget cannot be relied on to bind — see cta.blade.php.
     *  - Every utility class must exist in the compiled Tailwind bundle: run
     *    `npm run build` after editing (see project memory: prebuilt bundle).
     */
    $cfg = \App\Support\ContentAutopilotConfig::class;
    $recaptcha = \App\Support\Recaptcha::isEnabled();

    $monthly  = $cfg::displayPrice('monthly');
    $annual   = $cfg::displayPrice('annual');
    $first    = $cfg::displayPrice('first_month');
    $addonM   = $cfg::displayPrice('addon_monthly');
    $addonA   = $cfg::displayPrice('addon_annual');
    $articles = $cfg::monthlyArticlesPerWebsite();
    $trialDays = $cfg::trialDays();
    $trialArticles = $cfg::trialArticles();
    $trackerKeywords = $cfg::trackerKeywords();

    // Yearly saving, derived — so a price change in admin updates the badge.
    $savePct = $monthly > 0 ? (int) round(($monthly - $annual) / $monthly * 100) : 0;
    // Worked example for the extra-sites table: one included site + two add-ons.
    $threeSitesMonthly = $monthly + (2 * $addonM);
    $threeSitesAnnual  = $annual + (2 * $addonA);

    // Our own blog runs on the very product this page sells, so the freshest
    // proof is three real published articles. The table lives in the delivery
    // package; a fresh install without it must not 500 the landing page.
    $latestPosts = rescue(
        fn () => \Serfix\ContentAi\Models\Article::query()->published()->latest('published_at')->take(3)->get(),
        collect(),
        false,
    );

    $faqs = [
        ['q' => __('Is this content good enough to publish?'), 'a' => __('That is the whole design. Every draft is researched against real search results, written long-form to a brief, put through a humanising pass, then scored on 30+ on-page checks and rewritten until it passes. You still read it before it goes live — and you can edit any word.')],
        ['q' => __('Can I edit or reject an article?'), 'a' => __('Yes. Every draft opens in a full editor with the live SEO score, AI rewriting on selected text, image tools and the complete set of meta fields. Turn auto-publish off and nothing goes live without your approval.')],
        ['q' => __('How does it get published to my website?'), 'a' => __('Connect WordPress with a secure application password, or Shopify, Webflow, Wix, HubSpot, Sanity, Medusa, a Laravel site or any other platform through a webhook. Images upload into your own media library, and the SEO fields and schema travel with the post.')],
        ['q' => __('Do you write about my actual business?'), 'a' => __('We read your website first — what you sell, who you serve, how you talk — and you correct anything we got wrong before a word is written. Things you do not sell are never claimed, and competitor names are blocked automatically.')],
        ['q' => __('What if I want an article on a specific topic?'), 'a' => __('Add it to the calendar yourself and hit Write now. You can also reorder, reschedule or skip anything we planned.')],
        ['q' => __('How do I know it is working?'), 'a' => __('Each published article\'s keywords go into the tracker automatically — up to :n per website — with live Google positions, Search Console clicks and impressions, and Analytics visitors per article. We email you when rankings move up.', ['n' => number_format($trackerKeywords)])],
        ['q' => __('What does the free trial include?'), 'a' => __(':d days and :n articles on one website, with no card required. You see the research, the drafts and the scores before you decide.', ['d' => $trialDays, 'n' => $trialArticles])],
        ['q' => __('Can I cancel?'), 'a' => __('Anytime, with no contract. Articles already published stay on your site — they are yours.')],
    ];

    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $faqs),
    ];
@endphp
<x-marketing.page
    title="Content AI Autopilot — SEO Articles Written & Published For You"
    description="Serfix researches your niche, writes genuinely useful SEO articles, illustrates them, and publishes to your site on schedule — then tracks how they rank. Free trial, no card."
    active="content"
    :canonical="config('features.seo_platform_ui') ? null : url('/')"
>
    <x-slot:schema>
        <script type="application/ld+json">{!! json_encode($faqSchema, $jsonFlags) !!}</script>
    </x-slot:schema>

    @include('partials.content-landing.hero')
    @include('partials.content-landing.steps')
    @include('partials.content-landing.tour')
    @include('partials.content-landing.journey')
    @include('partials.content-landing.quality')
    @include('partials.content-landing.results')
    @include('partials.content-landing.publishing')
    @include('partials.content-landing.faq')
    @include('partials.content-landing.pricing')
    @include('partials.content-landing.cross-sell')
    @include('partials.content-landing.blog')
    @include('partials.content-landing.cta')

    @if ($recaptcha)
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif
</x-marketing.page>
