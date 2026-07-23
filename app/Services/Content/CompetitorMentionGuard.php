<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides — per client — whether generated articles may mention competitors,
 * and which brands are off-limits.
 *
 * Why this exists: an article for serfix.io recommended Semrush ("use Semrush
 * for an audit"). The writer has no idea a brand is a competitor unless told,
 * and this will happen to every client whose rivals are household names.
 *
 * The nuance that makes a plain blocklist wrong: the wizard's competitor list
 * is SERP competitors, not product competitors. Google outranks an SEO tool on
 * half its keywords, yet linking to a Google article is a perfectly good
 * citation. So an LLM classifies each competitor domain against what the
 * client actually sells:
 *
 *   block      → a product competitor; mentioning or recommending it can steer
 *                the client's readers to a rival
 *   reference  → competitor-ADJACENT but a valid citation/link target
 *
 * When the classifier finds real product competitors, blocking auto-enables
 * with a prominent notice in the wizard; the client can turn it off, remove
 * individual brands, or add their own terms. Enforcement is two-layer, same as
 * the style contract: a hard prompt rule (prevention) + a deterministic lint
 * that feeds the revise loop (cure).
 */
class CompetitorMentionGuard
{
    public const TOGGLE = 'block_competitor_mentions';

    /** Rough flash-tier classify cost, mirroring the other EST_* meter charges. */
    private const EST_ASSESS_USD = 0.01;

    public function __construct(
        private readonly ContentSetupInsights $insights,
        private readonly CrawlFetcher $fetcher,
    ) {}

    // ── state ───────────────────────────────────────────────────────────

    /**
     * The switch is tri-state on purpose: null = the client never chose, so
     * the assessment may auto-enable; true/false = an explicit human decision
     * that re-assessment must never override.
     */
    public function enabled(ContentPlan $plan): bool
    {
        return (bool) (($plan->toggles ?? [])[self::TOGGLE] ?? false);
    }

    public function decided(ContentPlan $plan): bool
    {
        return array_key_exists(self::TOGGLE, (array) ($plan->toggles ?? []));
    }

    /**
     * Guard-card view state — shared by the dashboard wizard/settings
     * (ContentCalendar) and the anonymous-onboarding twin (ContentWizard
     * trait) so the two don't drift the way the keyword-research ranking
     * did (2026-07-22, see rankAndFilter()).
     *
     * @return array{assessed: bool, enabled: bool, autoEnabled: bool, reason: string, terms: list<string>}
     */
    public function stateFor(ContentPlan $plan): array
    {
        $g = (array) ($plan->competitor_guard ?? []);
        $stats = (array) ($g['stats'] ?? []);

        return [
            'assessed' => $this->assessed($plan),
            'enabled' => $this->enabled($plan),
            // "We turned this on for you" banner: shows until the client
            // clicks the toggle themselves (setEnabled clears the marker).
            'autoEnabled' => $this->autoEnabled($plan),
            'reason' => (string) ($g['reason'] ?? ''),
            'terms' => $this->terms($plan),
            // Phase E: the guard's other half — classified references (valid
            // citation sources) rendered as their own chip group, and the
            // value counter that makes the feature visible after setup.
            'mode' => $this->mode($plan),
            'references' => array_values(array_filter(array_map(
                static fn ($d) => mb_strtolower(trim((string) $d)), (array) ($g['references'] ?? [])
            ))),
            'stats' => [
                'articles_checked' => (int) ($stats['articles_checked'] ?? 0),
                'mentions_removed' => (int) ($stats['mentions_removed'] ?? 0),
            ],
        ];
    }

    /**
     * Guard policy mode (Phase E). Explicit `guard['mode']` wins; otherwise
     * the site type's default (ContentSiteTypeProfiles::guard_default —
     * affiliates default to brands_required, resellers to stocked_only,
     * brands/local/saas/b2b to protect, blogs/nonprofits to off). A null
     * site type defaults to protect = exactly the pre-mode behavior.
     */
    public function mode(ContentPlan $plan): string
    {
        $explicit = (string) ((($plan->competitor_guard ?? [])['mode'] ?? ''));
        if (in_array($explicit, [
            \App\Support\ContentSiteTypeProfiles::GUARD_PROTECT,
            \App\Support\ContentSiteTypeProfiles::GUARD_OFF,
            \App\Support\ContentSiteTypeProfiles::GUARD_BRANDS_REQUIRED,
            \App\Support\ContentSiteTypeProfiles::GUARD_STOCKED_ONLY,
        ], true)) {
            return $explicit;
        }

        return \App\Support\ContentSiteTypeProfiles::profile($plan->site_type)['guard_default'];
    }

    /** Whether this mode ever blocks mentions (brands_required/off never do). */
    public function modeBlocks(ContentPlan $plan): bool
    {
        return in_array($this->mode($plan), [
            \App\Support\ContentSiteTypeProfiles::GUARD_PROTECT,
            \App\Support\ContentSiteTypeProfiles::GUARD_STOCKED_ONLY,
        ], true);
    }

    public function assessed(ContentPlan $plan): bool
    {
        return ! empty((($plan->competitor_guard ?? [])['assessed_at'] ?? null));
    }

    /**
     * A HUMAN decision: set the switch and drop the "we enabled this for you"
     * marker, so the wizard banner disappears once the client has weighed in.
     */
    public function setEnabled(ContentPlan $plan, bool $enabled): void
    {
        $toggles = (array) ($plan->toggles ?? []);
        $toggles[self::TOGGLE] = $enabled;
        $guard = (array) ($plan->competitor_guard ?? []);
        unset($guard['auto_enabled_at']);
        $plan->update(['toggles' => $toggles, 'competitor_guard' => $guard]);
    }

    /** True while the guard was switched on BY the assessment, not the client. */
    public function autoEnabled(ContentPlan $plan): bool
    {
        return $this->enabled($plan)
            && ! empty((($plan->competitor_guard ?? [])['auto_enabled_at'] ?? null));
    }

    /** Wipe the assessment so the next produce()/assess re-classifies. */
    public function invalidate(ContentPlan $plan): void
    {
        $guard = (array) ($plan->competitor_guard ?? []);
        unset($guard['assessed_at']);
        $plan->update(['competitor_guard' => $guard]);
    }

    // ── the blocked list ────────────────────────────────────────────────

    /**
     * Effective blocked terms: auto-classified brands + manual adds − removals.
     *
     * @return list<string> lowercase terms
     */
    public function terms(ContentPlan $plan): array
    {
        $guard = (array) ($plan->competitor_guard ?? []);

        $auto = [];
        foreach ((array) ($guard['auto'] ?? []) as $c) {
            $brand = mb_strtolower(trim((string) ($c['brand'] ?? '')));
            $auto[] = $brand;
            // Aliases classified alongside the brand ("urban company" → "uc",
            // product names). Removing the parent brand removes its aliases
            // too — the removed-diff below sees only exact matches, so alias
            // rows are dropped here when their brand is removed.
            if (! in_array($brand, array_map(
                static fn ($t) => mb_strtolower(trim((string) $t)), (array) ($guard['removed'] ?? [])
            ), true)) {
                foreach ((array) ($c['aliases'] ?? []) as $alias) {
                    $auto[] = mb_strtolower(trim((string) $alias));
                }
            }
        }
        $manual = array_map(
            static fn ($t) => mb_strtolower(trim((string) $t)),
            (array) ($guard['manual'] ?? [])
        );
        $removed = array_map(
            static fn ($t) => mb_strtolower(trim((string) $t)),
            (array) ($guard['removed'] ?? [])
        );

        $terms = array_values(array_filter(array_unique(array_diff(
            array_merge($auto, $manual),
            $removed
        )), static fn ($t) => $t !== '' && mb_strlen($t) >= 2));

        // Safety net: never block the client's OWN brand (a stale assessment
        // from before the own-brand competitor filter may still carry it).
        $ownBrand = $this->ownBrandToken($plan);
        if ($ownBrand !== null) {
            $terms = array_values(array_filter(
                $terms,
                static fn ($t) => ! str_contains($t, $ownBrand)
            ));
        }

        return $terms;
    }

    /**
     * Blocked DOMAINS (for the link lint). References are excluded here — a
     * link to google.com must survive for a client where Google is merely a
     * SERP neighbour.
     *
     * @return list<string>
     */
    public function blockedDomains(ContentPlan $plan): array
    {
        $guard = (array) ($plan->competitor_guard ?? []);
        $removed = array_map(
            static fn ($t) => mb_strtolower(trim((string) $t)),
            (array) ($guard['removed'] ?? [])
        );

        $domains = [];
        foreach ((array) ($guard['auto'] ?? []) as $c) {
            $brand = mb_strtolower(trim((string) ($c['brand'] ?? '')));
            $domain = mb_strtolower(trim((string) ($c['domain'] ?? '')));
            if ($domain !== '' && ! in_array($brand, $removed, true)) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Reorder an already-normalized, deduped competitor-domain list so
     * classified product rivals come first and classified references are
     * dropped entirely — the raw SERP-authority order systematically surfaces
     * directories/platforms ahead of the real rival (thryv.com for a cleaning
     * company, prod 2026-07-21/22), and every keyword-research call site that
     * "picks the top competitor(s)" must use THIS ranking, not the raw report
     * order, or the guard's classification is silently ignored downstream.
     * Raw order is returned unchanged when the plan hasn't been assessed yet.
     *
     * @param  list<string>  $candidates  lowercase, www-stripped domains
     * @return list<string>
     */
    public function rankAndFilter(ContentPlan $plan, array $candidates): array
    {
        $guard = (array) ($plan->competitor_guard ?? []);
        if (empty($guard['assessed_at'])) {
            return $candidates;
        }

        $normalize = static fn ($d) => strtolower(preg_replace('/^www\./', '', trim((string) $d)));
        $rivals = array_map($normalize, array_column((array) ($guard['auto'] ?? []), 'domain'));
        $references = array_map($normalize, (array) ($guard['references'] ?? []));

        // The client's own brand-variant domains are never research targets
        // (kayaliofficial.shop won kayali.com's research slot, 2026-07-23).
        $ownBrand = $this->ownBrandToken($plan);
        if ($ownBrand !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                fn ($d) => ! str_contains(mb_strtolower($this->brandFromDomain((string) $d)), $ownBrand)
            ));
        }

        $preferred = array_values(array_intersect($candidates, $rivals));
        $rest = array_values(array_diff($candidates, $rivals, $references));
        $ordered = array_merge($preferred, $rest);

        // Signal-based giant demotion (2026-07-23, flag-gated): a blocked
        // mega-retailer (sephora for kayali.com) used to WIN the single
        // research slot and burn the harvest on a million-keyword catalog.
        // Giants-by-scale and platform entities sink to the END — never
        // dropped, so a client whose only competitors are giants still gets
        // research.
        if (\App\Support\ContentAutopilotConfig::giantSignalsEnabled() && $ordered !== []) {
            $giant = fn (string $d) => $this->isGiantClass($plan, $d);
            $normal = array_values(array_filter($ordered, fn ($d) => ! $giant($d)));
            $giants = array_values(array_filter($ordered, $giant));
            $ordered = array_merge($normal, $giants);
        }

        return $ordered;
    }

    /** Entity type the classifier assigned a domain (null when unknown). */
    public function entityFor(ContentPlan $plan, string $domain): ?string
    {
        $entities = (array) ((($plan->competitor_guard ?? [])['entities'] ?? []));
        $domain = strtolower(preg_replace('/^www\./', '', trim($domain)));

        return isset($entities[$domain]) ? (string) $entities[$domain] : null;
    }

    /**
     * Giant-class = platform entity (retailer/marketplace/directory/media) OR
     * giant by scale (GiantDomains::isScaleGiant over the shared
     * domain_metrics asset). Used for research-slot demotion here and the
     * display grouping in ContentSetupInsights.
     */
    public function isGiantClass(ContentPlan $plan, string $domain): bool
    {
        $domain = strtolower(preg_replace('/^www\./', '', trim($domain)));
        if ($domain === '') {
            return false;
        }
        if (in_array($this->entityFor($plan, $domain), ['retailer', 'marketplace', 'directory', 'media'], true)) {
            return true;
        }

        try {
            $metrics = \App\Models\DomainMetric::query()
                ->whereIn('domain', array_filter([$domain, $this->clientHost($plan)]))
                ->get(['domain', 'dfs_referring_domains', 'moz_da', 'dfs_metrics'])
                ->keyBy('domain');
            $dm = $metrics[$domain] ?? null;
            if ($dm === null) {
                return false;
            }
            $client = $metrics[$this->clientHost($plan)] ?? null;

            return \App\Support\GiantDomains::isScaleGiant(
                is_numeric($dm->dfs_referring_domains) ? (int) $dm->dfs_referring_domains : null,
                is_numeric($dm->moz_da) ? (int) $dm->moz_da : null,
                ($v = data_get($dm->dfs_metrics, 'metrics.organic.count')) !== null && is_numeric($v) ? (int) $v : null,
                $client !== null && is_numeric($client->dfs_referring_domains) ? (int) $client->dfs_referring_domains : null,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function clientHost(ContentPlan $plan): string
    {
        $host = strtolower((string) ($plan->website?->normalized_domain ?: $plan->website?->domain ?: ''));

        return preg_replace('/^www\./', '', preg_replace('#^https?://#', '', $host) ?? $host) ?? $host;
    }

    /**
     * Per-topic term list for the writer/lint: a topic explicitly ABOUT a
     * competitor ("semrush alternatives") must be allowed to name it — that is
     * a legitimate, high-value article, not a leak.
     *
     * @return list<string>
     */
    public function termsForTopic(ContentPlan $plan, ContentTopic $topic): array
    {
        // Mode gate (Phase E): brands_required (affiliates — rival brands ARE
        // the content) and off never block, regardless of the toggle.
        if (! $this->enabled($plan) || ! $this->modeBlocks($plan)) {
            return [];
        }

        $keywords = mb_strtolower(implode(' ', array_merge(
            [(string) $topic->target_keyword],
            (array) ($topic->secondary_keywords ?? [])
        )));

        $terms = array_values(array_filter(
            $this->terms($plan),
            static fn (string $term) => ! str_contains($keywords, $term)
        ));

        // stocked_only (resellers): brands the shop itself carries are the
        // point of the content — anything named in the sell-offerings is
        // never blocked, only competing retailers are.
        if ($this->mode($plan) === \App\Support\ContentSiteTypeProfiles::GUARD_STOCKED_ONLY) {
            $stocked = mb_strtolower(implode(' ', (array) (($plan->offerings ?? [])['sell'] ?? [])));
            $terms = array_values(array_filter(
                $terms,
                static fn (string $term) => ! str_contains($stocked, $term)
            ));
        }

        return $terms;
    }

    // ── value counter (Phase E) ─────────────────────────────────────────

    /** An article finished producing with the guard active. */
    public function recordArticleChecked(ContentPlan $plan): void
    {
        $this->bumpStat($plan, 'articles_checked');
    }

    /** The revise loop actually stripped a competitor mention. */
    public function recordMentionRemoved(ContentPlan $plan): void
    {
        $this->bumpStat($plan, 'mentions_removed');
    }

    private function bumpStat(ContentPlan $plan, string $key): void
    {
        try {
            $guard = (array) ($plan->competitor_guard ?? []);
            $stats = (array) ($guard['stats'] ?? []);
            $stats[$key] = (int) ($stats[$key] ?? 0) + 1;
            $guard['stats'] = $stats;
            $plan->update(['competitor_guard' => $guard]);
        } catch (\Throwable) {
            // a lost counter tick must never fail an article
        }
    }

    // ── manual edits (wizard / settings) ────────────────────────────────

    public function addTerm(ContentPlan $plan, string $term): void
    {
        $term = mb_strtolower(trim($term));
        if ($term === '' || mb_strlen($term) > 60) {
            return;
        }
        $guard = (array) ($plan->competitor_guard ?? []);
        $manual = (array) ($guard['manual'] ?? []);
        // Adding a term also un-removes it — the intent is unambiguous.
        $guard['removed'] = array_values(array_diff((array) ($guard['removed'] ?? []), [$term]));
        if (! in_array($term, $manual, true) && count($manual) < 30) {
            $manual[] = $term;
        }
        $guard['manual'] = array_values($manual);
        $plan->update(['competitor_guard' => $guard]);

        // Retroactive scan (Phase E): a newly-blocked brand may already sit in
        // PUBLISHED articles the guard never saw. Cheap deterministic lint,
        // flags only — never edits published content.
        \App\Jobs\Content\ScanPublishedForBlockedTermsJob::dispatch($plan->id);
    }

    /** semrush.com → "semrush" — public for the reference→block chip action. */
    public function brandForDomain(string $domain): string
    {
        return $this->brandFromDomain($domain);
    }

    /**
     * The client's OWN brand token, derived from their domain (kayali.com →
     * "kayali"). Null when too short to match safely (<4 chars — "hp" would
     * false-positive everywhere). Used to keep the client's own brand out of
     * the blocked list, the competitor set, and keyword suggestions —
     * kayaliofficial.shop showed up both as a "competitor" and as a blocked
     * brand for kayali.com itself (prod 2026-07-23).
     */
    public function ownBrandToken(ContentPlan $plan): ?string
    {
        $host = $plan->website?->normalized_domain ?: $plan->website?->domain;
        if (! $host) {
            return null;
        }
        $brand = mb_strtolower($this->brandFromDomain((string) $host));

        return mb_strlen($brand) >= 4 ? $brand : null;
    }

    public function removeTerm(ContentPlan $plan, string $term): void
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return;
        }
        $guard = (array) ($plan->competitor_guard ?? []);
        $guard['manual'] = array_values(array_diff((array) ($guard['manual'] ?? []), [$term]));
        $removed = (array) ($guard['removed'] ?? []);
        if (! in_array($term, $removed, true)) {
            $removed[] = $term;
        }
        $guard['removed'] = array_values($removed);
        $plan->update(['competitor_guard' => $guard]);
    }

    // ── assessment ──────────────────────────────────────────────────────

    /**
     * Classify the plan's competitors against what the client sells and
     * persist the result. Auto-enables the toggle when real product
     * competitors are found AND the client has not decided yet.
     *
     * Fail-soft when no LLM is available: every competitor domain is blocked
     * under its domain-derived brand name. Over-blocking is the safe default —
     * the list is fully editable, while a silent competitor plug on a client's
     * blog is exactly the failure this guards against.
     */
    public function assess(ContentPlan $plan, ?LlmClient $llm = null): void
    {
        $entries = $this->competitorDomains($plan);
        $guard = (array) ($plan->competitor_guard ?? []);

        if ($entries === []) {
            $guard = array_merge($guard, [
                'assessed_at' => now()->toIso8601String(),
                'harmful' => false,
                'reason' => '',
                'auto' => [],
                'references' => [],
            ]);
            $plan->update(['competitor_guard' => $guard]);

            return;
        }

        $verdict = $this->classify($plan, $entries, $llm ?? LlmClientFactory::make());

        $guard = array_merge($guard, [
            'assessed_at' => now()->toIso8601String(),
            'harmful' => $verdict['harmful'],
            'reason' => $verdict['reason'],
            'auto' => $verdict['blocked'],
            'references' => $verdict['references'],
            'entities' => (array) ($verdict['entities'] ?? []),
        ]);
        // Auto-enable only while the client has never decided; stamp the
        // marker that drives the "we turned this on for you" banner. Modes
        // that never block (affiliate brands_required, blog off) are never
        // auto-enabled — protection is the wrong default for those businesses.
        if ($verdict['harmful'] && ! $this->decided($plan) && $this->modeBlocks($plan)) {
            $guard['auto_enabled_at'] = now()->toIso8601String();
            $toggles = (array) ($plan->toggles ?? []);
            $toggles[self::TOGGLE] = true;
            $plan->update(['competitor_guard' => $guard, 'toggles' => $toggles]);

            return;
        }

        $plan->update(['competitor_guard' => $guard]);
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * Merged auto + added − removed competitor domains, each flagged with
     * whether the CLIENT hand-added it. A manual add is a near-certain "this
     * IS my product competitor" signal the classifier must see — without it,
     * unknown regional brands (justlife.com for a UAE cleaning company, prod
     * 2026-07-22) read as random SERP neighbours and get waved through.
     *
     * @return list<array{domain: string, manual: bool}>
     */
    private function competitorDomains(ContentPlan $plan): array
    {
        $website = $plan->website;
        if ($website === null) {
            return [];
        }

        // Same host normalizer as ContentSetupInsights::normalizeHost() — the
        // override may hold a raw URL while insights carry the bare host.
        $normalize = static fn ($d) => strtolower(preg_replace('/^www\./', '', (string) (
            parse_url(str_contains((string) $d, '://') ? (string) $d : 'https://'.trim((string) $d), PHP_URL_HOST) ?: trim((string) $d)
        )));

        $overrides = (array) ($plan->competitor_overrides ?? []);
        $manualSet = array_diff(
            array_map($normalize, (array) ($overrides['added'] ?? [])),
            array_map($normalize, (array) ($overrides['removed'] ?? []))
        );

        try {
            $insights = $this->insights->withOverrides(
                $this->insights->competitorAuthority($website),
                $plan
            );
        } catch (\Throwable) {
            $insights = null;
        }

        $domains = array_map(
            static fn ($c) => mb_strtolower(trim((string) ($c['domain'] ?? ''))),
            (array) ($insights['competitors'] ?? [])
        );

        // Insights may not have generated yet (report snapshot pending) — the
        // manually-added competitors are still known and still classifiable.
        if ($domains === []) {
            $domains = array_map(
                static fn ($d) => mb_strtolower(trim((string) $d)),
                (array) ($overrides['added'] ?? [])
            );
        }

        // The client's own brand is never their competitor: kayali.com's SERP
        // neighbours include kayaliofficial.shop (own shop / brand-squatter) —
        // classifying it would end up BLOCKING the client's own brand name.
        $ownBrand = $this->ownBrandToken($plan);
        if ($ownBrand !== null) {
            $domains = array_values(array_filter(
                $domains,
                fn ($d) => ! str_contains(mb_strtolower($this->brandFromDomain((string) $d)), $ownBrand)
            ));
        }

        return array_values(array_map(
            static fn ($d) => ['domain' => $d, 'manual' => in_array($normalize($d), $manualSet, true)],
            array_filter(array_unique($domains))
        ));
    }

    /**
     * Homepage title + meta description per domain, for the classifier prompt.
     * The flash-tier model cannot recognize regional brands from a bare
     * hostname (justlife.com read as "not a cleaning competitor", prod
     * 2026-07-22) — the site's own words fix that. Cached ~30 days per host
     * (`content:guard-ctx:{host}`); failures cache '' for 1 day so a dead
     * site isn't re-fetched every assessment but recovers quickly. Hard cap
     * of 8 fetches per assessment; every failure is soft — a domain without
     * context simply renders as a bare line.
     *
     * @param  list<string>  $domains  already ordered manual-first by the caller
     * @return array<string, string> domain => "Title — meta description" ('' when unknown)
     */
    private function domainContexts(array $domains): array
    {
        $contexts = [];
        $fetched = 0;
        foreach ($domains as $domain) {
            $host = strtolower(preg_replace('/^www\./', '', trim($domain)));
            $key = 'content:guard-ctx:'.$host;
            $cached = Cache::get($key);
            if (is_string($cached)) {
                $contexts[$domain] = $cached;
                continue;
            }
            if ($fetched >= 8) {
                $contexts[$domain] = '';
                continue;
            }
            $fetched++;
            $context = '';
            try {
                $res = $this->fetcher->fetch('https://'.$host.'/', timeout: 8);
                if (($res['ok'] ?? false) && (int) ($res['status'] ?? 0) < 400 && is_string($res['body'] ?? null) && $res['body'] !== '') {
                    $context = $this->titleAndDescription($res['body']);
                }
            } catch (\Throwable) {
                // fail soft: classification proceeds without context
            }
            Cache::put($key, $context, $context !== '' ? now()->addDays(30) : now()->addDay());
            $contexts[$domain] = $context;
        }

        return $contexts;
    }

    /**
     * First-40KB regex extraction — deliberately NOT HtmlAuditor (a full DOM
     * parse of up to 5MB is overkill for two tags).
     */
    private function titleAndDescription(string $html): string
    {
        $head = substr($html, 0, 40_000);
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/si', $head, $m)
            ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)) : '';
        $desc = '';
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/si', $head, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/si', $head, $m)) {
            $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }
        $parts = array_filter([mb_substr($title, 0, 120), mb_substr($desc, 0, 200)]);

        return (string) preg_replace('/\s+/u', ' ', implode(' — ', $parts));
    }

    /**
     * @param  list<array{domain: string, manual: bool}>  $entries
     * @return array{harmful: bool, reason: string, blocked: list<array{brand:string,domain:string,reason:string}>, references: list<string>}
     */
    private function classify(ContentPlan $plan, array $entries, LlmClient $llm): array
    {
        $meter = app(ContentLlmSpendMeter::class);
        $domains = array_column($entries, 'domain');

        if (! $llm->isAvailable() || $meter->exhausted()) {
            return $this->failSoft($domains, 'auto (no AI available)');
        }

        $sell = implode('; ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 10));
        $dontSell = implode('; ', array_slice((array) (($plan->offerings ?? [])['dont_sell'] ?? []), 0, 10));
        // Manual adds first — they matter most and must fit inside both the
        // 20-line prompt cap and domainContexts()'s 8-fetch cap.
        $entries = array_slice([
            ...array_values(array_filter($entries, static fn ($e) => $e['manual'])),
            ...array_values(array_filter($entries, static fn ($e) => ! $e['manual'])),
        ], 0, 20);
        $contexts = $this->domainContexts(array_column($entries, 'domain'));
        $list = implode("\n", array_map(static function ($e) use ($contexts) {
            $line = '- '.$e['domain'];
            if (($ctx = $contexts[$e['domain']] ?? '') !== '') {
                $line .= ' — site says: "'.mb_substr($ctx, 0, 300).'"';
            }
            if ($e['manual']) {
                $line .= ' (added by the client as their competitor)';
            }

            return $line;
        }, $entries));
        $stage = ContentAutopilotConfig::modelFor('ideate');

        try {
            $decoded = $llm->completeJson([
                ['role' => 'system', 'content' => 'You assess brand-safety for automated content. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                A business publishes blog articles automatically. Decide which of its
                search competitors must NEVER be mentioned or recommended in those
                articles.

                BUSINESS:
                {$plan->business_description}
                They sell: {$sell}
                They do NOT sell: {$dontSell}

                COMPETITOR DOMAINS. Lines marked "(added by the client as their
                competitor)" were hand-picked by the business owner as direct rivals;
                the rest merely rank for the same keywords. Where available, each line
                carries the site's own homepage title/description:
                {$list}

                For each domain decide:
                - "block": a PRODUCT competitor — it sells what this business sells, so
                  naming or recommending it in an article could send readers to a rival.
                  Give the everyday brand name people write in prose (e.g. semrush.com
                  -> "Semrush"), plus up to 4 "aliases": common abbreviations, alternate
                  spellings or flagship product names a writer might use instead
                  (e.g. "UC" for Urban Company). Empty list when none exist.

                Also tag EVERY domain with "entity" — what kind of business it is:
                "brand" (sells its own products), "retailer" (shop selling many brands),
                "marketplace" (third-party seller platform), "directory", "media"
                (news/magazine/community), "service" (service business), or "other".
                - "reference": NOT a product competitor — an encyclopedia, news site,
                  platform, directory or authority that is a perfectly normal citation
                  (e.g. google.com or wikipedia.org for most businesses).

                Rules:
                - A domain the client added as their competitor is almost certainly a
                  product competitor. Classify it "block" unless it is unmistakably a
                  pure reference (a search engine, an encyclopedia, or a major news
                  outlet).
                - When you cannot determine what a domain sells (unknown brand, no page
                  context given), classify it "block". Over-blocking is safe — the
                  client can remove any entry with one click, while a missed rival gets
                  recommended on the client's own blog.

                Also decide overall: is competitor mention a real risk for this
                business (true when at least one genuine product competitor exists)?
                Give a one-sentence reason written TO the business owner.

                Return JSON:
                {"harmful": bool, "reason": "...", "domains": [{"domain": "...", "verdict": "block|reference", "entity": "brand|retailer|marketplace|directory|media|service|other", "brand": "...", "aliases": ["..."], "why": "..."}]}
                PROMPT],
            ], array_filter([
                'temperature' => 0.1,
                'max_tokens' => 1200,
                'timeout' => 40,
                'json_object' => true,
                'model' => $stage['model'],
                '__source' => 'content_autopilot.competitor_guard',
                '__unmetered' => true,
            ]));
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.competitor_guard_failed', ['plan_id' => $plan->id, 'error' => $e->getMessage()]);
            $decoded = null;
        }

        if (! is_array($decoded) || ! isset($decoded['domains'])) {
            return $this->failSoft($domains, 'auto (assessment unavailable)');
        }

        $meter->add(self::EST_ASSESS_USD);

        $blocked = [];
        $references = [];
        $entities = [];
        foreach ((array) $decoded['domains'] as $row) {
            $domain = mb_strtolower(trim((string) ($row['domain'] ?? '')));
            if ($domain === '' || ! in_array($domain, $domains, true)) {
                continue; // never let the model invent domains
            }
            $entity = mb_strtolower(trim((string) ($row['entity'] ?? '')));
            if (in_array($entity, ['brand', 'retailer', 'marketplace', 'directory', 'media', 'service', 'other'], true)) {
                $entities[$domain] = $entity;
            }
            if (($row['verdict'] ?? '') === 'block') {
                $brand = trim((string) ($row['brand'] ?? '')) ?: $this->brandFromDomain($domain);
                $blocked[] = [
                    'brand' => mb_strtolower($brand),
                    'domain' => $domain,
                    'reason' => mb_substr(trim((string) ($row['why'] ?? '')), 0, 200),
                    'aliases' => array_slice(array_values(array_filter(array_map(
                        static fn ($a) => mb_strtolower(trim((string) $a)),
                        is_array($row['aliases'] ?? null) ? $row['aliases'] : []
                    ), static fn ($a) => $a !== '' && mb_strlen($a) >= 2 && mb_strlen($a) <= 60)), 0, 4),
                ];
            } else {
                $references[] = $domain;
            }
        }

        return [
            // Derived from the per-domain verdicts, NOT the model's separate
            // boolean: on staging the classifier correctly marked every domain
            // a reference yet still said harmful=true from abstract reasoning
            // about hypothetical rivals — which would auto-enable the guard
            // with ZERO blocked brands (a banner with no chips).
            'harmful' => $blocked !== [],
            'reason' => mb_substr(trim((string) ($decoded['reason'] ?? '')), 0, 300),
            'blocked' => $blocked,
            'references' => array_values(array_unique($references)),
            'entities' => $entities,
        ];
    }

    /**
     * @param  list<string>  $domains
     * @return array{harmful: bool, reason: string, blocked: list<array{brand:string,domain:string,reason:string}>, references: list<string>}
     */
    private function failSoft(array $domains, string $marker): array
    {
        return [
            'harmful' => $domains !== [],
            'reason' => $marker,
            'blocked' => array_map(fn (string $d) => [
                'brand' => $this->brandFromDomain($d),
                'domain' => $d,
                'reason' => $marker,
            ], $domains),
            'references' => [],
        ];
    }

    /** semrush.com → semrush; www.ahrefs.co.uk → ahrefs */
    private function brandFromDomain(string $domain): string
    {
        $host = strtolower(preg_replace('/^www\./', '', trim($domain)) ?? $domain);
        $labels = explode('.', $host);
        // The LAST label of a hostname is always a TLD — drop it
        // unconditionally (the old known-TLD allowlist turned unknown TLDs
        // into the "brand": kayaliofficial.shop → "shop", rival.test →
        // "test"; 2026-07-23). Then strip a second-level registry label
        // (co.uk, com.au) so the registrable name remains. Subdomains still
        // resolve to the registrable name (blog.kayali.com → kayali).
        if (count($labels) > 1) {
            array_pop($labels);
        }
        while (count($labels) > 1 && in_array(end($labels), ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu'], true)) {
            array_pop($labels);
        }

        return (string) end($labels);
    }
}
