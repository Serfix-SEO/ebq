<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
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

    public function __construct(private readonly ContentSetupInsights $insights) {}

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

        $auto = array_map(
            static fn ($c) => mb_strtolower(trim((string) ($c['brand'] ?? ''))),
            (array) ($guard['auto'] ?? [])
        );
        $manual = array_map(
            static fn ($t) => mb_strtolower(trim((string) $t)),
            (array) ($guard['manual'] ?? [])
        );
        $removed = array_map(
            static fn ($t) => mb_strtolower(trim((string) $t)),
            (array) ($guard['removed'] ?? [])
        );

        return array_values(array_filter(array_unique(array_diff(
            array_merge($auto, $manual),
            $removed
        )), static fn ($t) => $t !== ''));
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
     * Per-topic term list for the writer/lint: a topic explicitly ABOUT a
     * competitor ("semrush alternatives") must be allowed to name it — that is
     * a legitimate, high-value article, not a leak.
     *
     * @return list<string>
     */
    public function termsForTopic(ContentPlan $plan, ContentTopic $topic): array
    {
        if (! $this->enabled($plan)) {
            return [];
        }

        $keywords = mb_strtolower(implode(' ', array_merge(
            [(string) $topic->target_keyword],
            (array) ($topic->secondary_keywords ?? [])
        )));

        return array_values(array_filter(
            $this->terms($plan),
            static fn (string $term) => ! str_contains($keywords, $term)
        ));
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
        $domains = $this->competitorDomains($plan);
        $guard = (array) ($plan->competitor_guard ?? []);

        if ($domains === []) {
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

        $verdict = $this->classify($plan, $domains, $llm ?? LlmClientFactory::make());

        $guard = array_merge($guard, [
            'assessed_at' => now()->toIso8601String(),
            'harmful' => $verdict['harmful'],
            'reason' => $verdict['reason'],
            'auto' => $verdict['blocked'],
            'references' => $verdict['references'],
        ]);
        // Auto-enable only while the client has never decided; stamp the
        // marker that drives the "we turned this on for you" banner.
        if ($verdict['harmful'] && ! $this->decided($plan)) {
            $guard['auto_enabled_at'] = now()->toIso8601String();
            $toggles = (array) ($plan->toggles ?? []);
            $toggles[self::TOGGLE] = true;
            $plan->update(['competitor_guard' => $guard, 'toggles' => $toggles]);

            return;
        }

        $plan->update(['competitor_guard' => $guard]);
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @return list<string> merged auto + added − removed competitor domains */
    private function competitorDomains(ContentPlan $plan): array
    {
        $website = $plan->website;
        if ($website === null) {
            return [];
        }

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
            $overrides = (array) ($plan->competitor_overrides ?? []);
            $domains = array_map(
                static fn ($d) => mb_strtolower(trim((string) $d)),
                (array) ($overrides['added'] ?? [])
            );
        }

        return array_values(array_filter(array_unique($domains)));
    }

    /**
     * @param  list<string>  $domains
     * @return array{harmful: bool, reason: string, blocked: list<array{brand:string,domain:string,reason:string}>, references: list<string>}
     */
    private function classify(ContentPlan $plan, array $domains, LlmClient $llm): array
    {
        $meter = app(ContentLlmSpendMeter::class);

        if (! $llm->isAvailable() || $meter->exhausted()) {
            return $this->failSoft($domains, 'auto (no AI available)');
        }

        $sell = implode('; ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 10));
        $dontSell = implode('; ', array_slice((array) (($plan->offerings ?? [])['dont_sell'] ?? []), 0, 10));
        $list = implode("\n", array_map(static fn ($d) => '- '.$d, array_slice($domains, 0, 20)));
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

                SEARCH COMPETITORS (rank for the same keywords — NOT necessarily
                product competitors):
                {$list}

                For each domain decide:
                - "block": a PRODUCT competitor — it sells what this business sells, so
                  naming or recommending it in an article could send readers to a rival.
                  Give the everyday brand name people write in prose (e.g. semrush.com
                  -> "Semrush").
                - "reference": NOT a product competitor — an encyclopedia, news site,
                  platform, directory or authority that is a perfectly normal citation
                  (e.g. google.com or wikipedia.org for most businesses).

                Also decide overall: is competitor mention a real risk for this
                business (true when at least one genuine product competitor exists)?
                Give a one-sentence reason written TO the business owner.

                Return JSON:
                {"harmful": bool, "reason": "...", "domains": [{"domain": "...", "verdict": "block|reference", "brand": "...", "why": "..."}]}
                PROMPT],
            ], array_filter([
                'temperature' => 0.1,
                'max_tokens' => 1200,
                'timeout' => 40,
                'json_object' => true,
                'model' => $stage['model'],
                '__source' => 'content_autopilot.competitor_guard',
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
        foreach ((array) $decoded['domains'] as $row) {
            $domain = mb_strtolower(trim((string) ($row['domain'] ?? '')));
            if ($domain === '' || ! in_array($domain, $domains, true)) {
                continue; // never let the model invent domains
            }
            if (($row['verdict'] ?? '') === 'block') {
                $brand = trim((string) ($row['brand'] ?? '')) ?: $this->brandFromDomain($domain);
                $blocked[] = [
                    'brand' => mb_strtolower($brand),
                    'domain' => $domain,
                    'reason' => mb_substr(trim((string) ($row['why'] ?? '')), 0, 200),
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
        // Drop TLD labels (last one, plus a second-level like co/com in co.uk).
        while (count($labels) > 1 && in_array(end($labels), [
            'com', 'net', 'org', 'io', 'co', 'ai', 'app', 'dev', 'uk', 'de', 'fr', 'es', 'it', 'nl', 'au', 'ca', 'us', 'pk', 'in', 'ae',
        ], true)) {
            array_pop($labels);
        }

        return (string) end($labels);
    }
}
