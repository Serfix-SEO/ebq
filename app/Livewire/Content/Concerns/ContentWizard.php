<?php

namespace App\Livewire\Content\Concerns;

use App\Jobs\AssessCompetitorGuardJob;
use App\Jobs\PlanContentTopicsJob;
use App\Jobs\PrepareContentKeywordInsightsJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentKeywordInsights;
use App\Services\Content\ContentSetupInsights;
use App\Services\Content\SiteProfileExtractor;
use App\Support\ContentImageStyles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared 7-step Content Autopilot wizard (business → offerings → how-it-works →
 * images → competitors → keyword research → first articles). The dashboard
 * (ContentCalendar) has its own copy tied to the signed-in user; this trait is
 * the ANONYMOUS twin used by PublicOnboarding, operating on a provisional
 * website (owned by the system "content-leads" user) with NO entitlement gate —
 * onboarding happens before billing. The host must provide website()/plan().
 *
 * The blade markup is a single shared partial (livewire/content/partials/wizard)
 * included by both, so the two flows stay pixel-identical.
 */
trait ContentWizard
{
    // ── Wizard state ──
    public int $wizardStep = 1;
    public ?string $draftPlanId = null;
    public bool $analyzing = false;

    public string $brandName = '';
    public string $language = 'English';
    public string $country = 'global';
    public string $businessDescription = '';
    // Site type drives the whole offer-spine (intent weights, guard posture,
    // CTA framing). '' = unclassified => pipeline behaves type-blind.
    public string $siteType = '';
    public string $siteTypeSource = '';
    public string $audience = '';
    // Classifier-assessed, type-independent YMYL flag (null = unknown). No
    // UI — silently drives the writer's conservative-claims rule.
    public ?bool $ymylFlag = null;

    /** @var list<string> */
    public array $sellItems = [];
    /** @var list<string> */
    public array $dontSellItems = [];
    public string $newSell = '';
    public string $newDont = '';
    public string $newCompetitorDomain = '';
    public string $newBlockedTerm = '';

    public array $structureToggles = ['key_takeaways' => true, 'toc' => true, 'faq' => true, 'featured_image' => true];

    public bool $imagesEnabled = true;
    public string $imageStyle = 'photographic';

    /** Resume wizard state from an existing (provisional) plan, if any. */
    protected function bootWizard(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        $this->brandName = $this->brandName ?: $this->guessBrand($website);

        $existing = $this->plan();
        if ($existing !== null) {
            $this->draftPlanId = $existing->id;
            $this->businessDescription = $this->businessDescription ?: (string) $existing->business_description;
            $this->siteType = $this->siteType ?: (string) $existing->site_type;
            $this->siteTypeSource = $this->siteTypeSource ?: (string) $existing->site_type_source;
            $this->audience = $this->audience ?: (string) $existing->audience;
            $this->ymylFlag ??= $existing->ymyl;
            $offerings = (array) ($existing->offerings ?? []);
            $this->sellItems = $this->sellItems ?: array_values((array) ($offerings['sell'] ?? []));
            $this->dontSellItems = $this->dontSellItems ?: array_values((array) ($offerings['dont_sell'] ?? []));
            $this->structureToggles = [
                'key_takeaways' => $existing->toggle('key_takeaways'),
                'toc' => $existing->toggle('toc'),
                'faq' => $existing->toggle('faq'),
                'featured_image' => $existing->toggle('featured_image'),
            ];
            $this->imagesEnabled = $existing->images_enabled === null ? true : (bool) $existing->images_enabled;
            $this->imageStyle = ContentImageStyles::isValid($existing->image_style)
                ? (string) $existing->image_style : ContentImageStyles::default();

            return;
        }

        $this->analyzing = true;
    }

    /** Auto-detect the business profile from crawl data (cached LLM call). */
    public function analyzeSite(): void
    {
        $this->analyzing = false;

        $website = $this->website();

        // Cheap ccTLD guess, no API call — independent of the profile gate
        // below so it still runs even once a plan exists.
        if ($website !== null && (blank($this->country) || $this->country === 'global') && blank($this->plan()?->country)) {
            $this->country = $this->detectCountryFromDomain($website) ?? 'global';
        }

        if ($website === null || $this->plan() !== null) {
            return;
        }

        // Fail soft: a QuotaExceededException here must never bubble up (same
        // wire:init/redirect-loop hazard as the dashboard's ContentCalendar
        // copy, prod 2026-07-22 — see infra/content-autopilot/README.md).
        try {
            $profile = app(SiteProfileExtractor::class)->extract($website);
        } catch (\App\Exceptions\QuotaExceededException) {
            $profile = [];
        }

        if ($this->businessDescription === '') {
            $this->businessDescription = (string) ($profile['description'] ?? '');
        }
        if ($this->sellItems === [] && ! empty($profile['sell'])) {
            $this->sellItems = array_values($profile['sell']);
        }
        if ($this->dontSellItems === [] && ! empty($profile['dont_sell'])) {
            $this->dontSellItems = array_values($profile['dont_sell']);
        }
        // A user's chip click always outranks re-detection.
        if ($this->siteTypeSource !== 'user' && $this->siteType === ''
            && \App\Support\ContentSiteTypeProfiles::isValid($profile['site_type'] ?? null)) {
            $this->siteType = (string) $profile['site_type'];
            $this->siteTypeSource = 'auto';
        }
        if ($this->audience === '') {
            $this->audience = (string) ($profile['audience'] ?? '');
        }
        if ($this->ymylFlag === null && is_bool($profile['ymyl'] ?? null)) {
            $this->ymylFlag = $profile['ymyl'];
        }

        if ($this->businessDescription === '') {
            try {
                if ($website->crawl_site_id) {
                    $this->businessDescription = (string) (DB::table('website_pages')
                        ->where('crawl_site_id', $website->crawl_site_id)
                        ->whereNotNull('meta_description')->where('meta_description', '!=', '')
                        ->orderBy('url')->limit(1)->value('meta_description') ?? '');
                }
            } catch (\Throwable) {
            }
        }
    }

    /** @see \App\Livewire\Content\ContentCalendar::detectCountryFromDomain() (kept in sync — see infra doc) */
    private function detectCountryFromDomain(Website $website): ?string
    {
        $host = strtolower(trim((string) ($website->normalized_domain ?: $website->domain)));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $labels = explode('.', rtrim($host, '.'));
        $tld = end($labels);
        if ($tld === false || $tld === '') {
            return null;
        }

        static $genericUse = ['co', 'io', 'ai', 'me', 'tv', 'fm', 'cc', 'ly', 'gg', 'to', 'sh', 'app', 'dev', 'xyz'];
        if (in_array($tld, $genericUse, true)) {
            return null;
        }

        return array_key_exists($tld, \App\Support\KeywordFinderLocations::COUNTRIES) ? $tld : null;
    }

    public function goToStep(int $step): void
    {
        $max = $this->draftPlanId !== null ? 8 : 2;
        $this->wizardStep = max(1, min($step, $max));
    }

    /** Step-1 site-type chip click — an explicit human decision. */
    public function selectSiteType(string $type): void
    {
        if (! \App\Support\ContentSiteTypeProfiles::isValid($type)) {
            return;
        }
        $this->siteType = $type;
        $this->siteTypeSource = 'user';
        // Persist immediately when a plan already exists (Settings revisit) —
        // same immediate-write pattern as image settings.
        $this->plan()?->update(['site_type' => $type, 'site_type_source' => 'user']);
    }

    /** Step 1 → 2 */
    public function toOfferings(): void
    {
        $this->validate([
            'businessDescription' => 'required|string|min:30|max:1000',
            'country' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\KeywordFinderLocations::countryOptions()))],
        ], [], ['businessDescription' => __('business description'), 'country' => __('target country')]);

        $this->wizardStep = 2;
    }

    public function addSell(): void
    {
        $v = trim($this->newSell);
        if ($v !== '') {
            $this->sellItems[] = mb_substr($v, 0, 120);
            $this->newSell = '';
        }
    }

    public function addDont(): void
    {
        $v = trim($this->newDont);
        if ($v !== '') {
            $this->dontSellItems[] = mb_substr($v, 0, 120);
            $this->newDont = '';
        }
    }

    public function removeSell(int $i): void
    {
        unset($this->sellItems[$i]);
        $this->sellItems = array_values($this->sellItems);
    }

    public function removeDont(int $i): void
    {
        unset($this->dontSellItems[$i]);
        $this->dontSellItems = array_values($this->dontSellItems);
    }

    public function moveSell(int $from, int $to): void
    {
        if (! isset($this->sellItems[$from]) || $from === $to || $to < 0 || $to >= count($this->sellItems)) {
            return;
        }
        $item = $this->sellItems[$from];
        array_splice($this->sellItems, $from, 1);
        array_splice($this->sellItems, $to, 0, [$item]);
    }

    /**
     * Step 2 → 3: persist the DRAFT plan on the provisional website and kick off
     * topic ideation + keyword research. NO entitlement gate — this is the
     * pre-signup public flow; billing/coverage happens after account creation.
     */
    public function toHowItWorks(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }

        $sell = array_values(array_filter(array_map('trim', $this->sellItems)));
        $dont = array_values(array_filter(array_map('trim', $this->dontSellItems)));
        $existing = ContentPlan::query()->where('website_id', $website->id)->first();

        $plan = ContentPlan::query()->updateOrCreate(
            ['website_id' => $website->id],
            [
                'status' => $existing?->status ?? ContentPlan::STATUS_DRAFT,
                'articles_per_week' => $existing?->articles_per_week ?? 7,
                'article_length' => $existing?->article_length ?? 2000,
                'auto_publish' => $existing?->auto_publish ?? false,
                'review_hours' => $existing?->review_hours ?? 24,
                'images_enabled' => $existing?->images_enabled ?? true,
                'image_style' => $existing?->image_style ?? ContentImageStyles::default(),
                'toggles' => array_merge(
                    $existing?->toggles ?? ['toc' => true, 'key_takeaways' => true, 'faq' => true,
                        'external_links' => true, 'cta_enabled' => false],
                    [
                        'key_takeaways' => (bool) ($this->structureToggles['key_takeaways'] ?? true),
                        'toc' => (bool) ($this->structureToggles['toc'] ?? true),
                        'faq' => (bool) ($this->structureToggles['faq'] ?? true),
                        'featured_image' => (bool) ($this->structureToggles['featured_image'] ?? true),
                    ]
                ),
                'business_description' => $this->businessDescription,
                'offerings' => ['sell' => array_slice($sell, 0, 12), 'dont_sell' => array_slice($dont, 0, 12)],
                'site_type' => \App\Support\ContentSiteTypeProfiles::isValid($this->siteType) ? $this->siteType : null,
                'site_type_source' => $this->siteType !== '' ? ($this->siteTypeSource ?: 'auto') : null,
                'audience' => $this->audience !== '' ? mb_substr($this->audience, 0, 500) : null,
                'ymyl' => $this->ymylFlag,
                'language' => $this->language ?: 'en',
                'country' => $this->country ?: null,
            ]
        );
        $this->draftPlanId = $plan->id;

        if ($plan->topics()->count() === 0) {
            PlanContentTopicsJob::dispatch($plan->id);
        }
        PrepareContentKeywordInsightsJob::dispatch($plan->id);

        $this->wizardStep = 3;
    }

    public function toImages(): void
    {
        $this->wizardStep = 4;
    }

    public function toCompetitors(): void
    {
        $this->wizardStep = 5;
    }

    public function toggleImages(): void
    {
        $this->imagesEnabled = ! $this->imagesEnabled;
        $this->persistImageSettings();
    }

    public function selectImageStyle(string $key): void
    {
        if (ContentImageStyles::isValid($key)) {
            $this->imageStyle = $key;
            $this->imagesEnabled = true;
            $this->persistImageSettings();
        }
    }

    private function persistImageSettings(): void
    {
        $this->plan()?->update([
            'images_enabled' => $this->imagesEnabled,
            'image_style' => ContentImageStyles::isValid($this->imageStyle)
                ? $this->imageStyle : ContentImageStyles::default(),
        ]);
    }

    public function toggleStructure(string $key): void
    {
        if (! array_key_exists($key, $this->structureToggles)) {
            return;
        }
        $this->structureToggles[$key] = ! $this->structureToggles[$key];

        $plan = $this->plan();
        if ($plan !== null) {
            $toggles = (array) ($plan->toggles ?? []);
            $toggles[$key] = $this->structureToggles[$key];
            $plan->update(['toggles' => $toggles]);
        }
    }

    public function loadCompetitors(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        $insights = app(ContentSetupInsights::class);
        if ($insights->competitorAuthority($website) === null) {
            $insights->ensureGenerating($website);
        }
        // Mention-guard classification runs alongside — the keyword-research
        // step's guard card polls and fills in when it lands.
        if (($plan = $this->plan()) !== null
            && ! app(CompetitorMentionGuard::class)->assessed($plan)) {
            AssessCompetitorGuardJob::dispatch($plan->id);
        }
    }

    public function refreshCompetitors(): void
    {
        $website = $this->website();
        if ($website !== null) {
            app(ContentSetupInsights::class)->forget($website);
        }
    }

    public function resetCompetitors(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $plan->update(['competitor_overrides' => null]);
        $this->reassessGuard($plan);
    }

    public function addCompetitor(): void
    {
        $this->resetErrorBag('newCompetitorDomain');
        $domain = $this->normalizeCompetitorDomain($this->newCompetitorDomain);
        $this->newCompetitorDomain = '';

        if ($domain === null) {
            $this->addError('newCompetitorDomain', __('Enter a valid competitor domain.'));

            return;
        }

        $plan = $this->plan();
        if ($plan === null) {
            return;
        }

        $overrides = (array) ($plan->competitor_overrides ?? []);
        $added = array_map('strtolower', (array) ($overrides['added'] ?? []));
        $removed = array_values(array_diff((array) ($overrides['removed'] ?? []), [$domain]));

        if (count($added) >= 8) {
            $this->addError('newCompetitorDomain', __('You can add up to 8 competitors.'));

            return;
        }
        if (! in_array($domain, $added, true)) {
            $added[] = $domain;
        }

        $plan->update(['competitor_overrides' => ['added' => array_values($added), 'removed' => $removed]]);
        $this->reassessGuard($plan);
        // "Est. traffic/mo" / "Organic keywords" come from DomainMetric.dfs_metrics,
        // otherwise ONLY populated for auto-discovered competitors (see the
        // ContentCalendar twin of this method). Idempotent/cheap.
        \App\Jobs\Content\EnrichCompetitorDomainMetricsJob::dispatch($plan->website_id, [$domain]);
    }

    public function removeCompetitor(string $domain): void
    {
        $domain = strtolower(trim($domain));
        $plan = $this->plan();
        if ($plan === null || $domain === '') {
            return;
        }

        $overrides = (array) ($plan->competitor_overrides ?? []);
        $added = array_values(array_diff((array) ($overrides['added'] ?? []), [$domain]));
        $removed = (array) ($overrides['removed'] ?? []);
        if (! in_array($domain, $removed, true)) {
            $removed[] = $domain;
        }

        $plan->update(['competitor_overrides' => ['added' => $added, 'removed' => array_values($removed)]]);
        $this->reassessGuard($plan);
    }

    /** The competitor list changed — the guard's classification is stale. */
    private function reassessGuard(ContentPlan $plan): void
    {
        app(CompetitorMentionGuard::class)->invalidate($plan);
        AssessCompetitorGuardJob::dispatch($plan->id);
    }

    // ── Competitor-mention guard (wizard card) ──────────────────────────

    public function toggleCompetitorGuard(): void
    {
        $plan = $this->plan();
        if ($plan === null) {
            return;
        }
        $guard = app(CompetitorMentionGuard::class);
        // An explicit click is a human decision — recorded as such, so a later
        // re-assessment never flips it back.
        $guard->setEnabled($plan, ! $guard->enabled($plan));
    }

    public function addBlockedTerm(): void
    {
        $this->resetErrorBag('newBlockedTerm');
        $term = trim($this->newBlockedTerm);
        $this->newBlockedTerm = '';
        $plan = $this->plan();
        if ($plan === null || $term === '') {
            return;
        }
        if (mb_strlen($term) > 60) {
            $this->addError('newBlockedTerm', __('Keep blocked terms under 60 characters.'));

            return;
        }
        app(CompetitorMentionGuard::class)->addTerm($plan, $term);
    }

    public function removeBlockedTerm(string $term): void
    {
        $plan = $this->plan();
        if ($plan !== null) {
            app(CompetitorMentionGuard::class)->removeTerm($plan, $term);
        }
    }

    /** Guard card: block a classified reference's brand anyway (Phase E). */
    public function blockReference(string $domain): void
    {
        $plan = $this->plan();
        $domain = strtolower(trim($domain));
        if ($plan === null || $domain === '') {
            return;
        }
        $guard = app(CompetitorMentionGuard::class);
        $guard->addTerm($plan, $guard->brandForDomain($domain));
    }

    private function normalizeCompetitorDomain(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $host = parse_url(str_contains($raw, '://') ? $raw : 'https://'.$raw, PHP_URL_HOST) ?: $raw;
        $host = strtolower(preg_replace('/^www\./', '', $host));

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return null;
        }

        $mine = $this->website()?->normalized_domain ?: $this->website()?->domain;
        if ($mine && strtolower(preg_replace('/^www\./', '', $mine)) === $host) {
            return null;
        }

        return $host;
    }

    public function toKeywordResearch(): void
    {
        $this->wizardStep = 6;
        if (($plan = $this->plan()) !== null) {
            PrepareContentKeywordInsightsJob::dispatch($plan->id);
            // Competitor list is final once the user leaves the step —
            // (re)classify it for the mention guard if needed.
            if (! app(CompetitorMentionGuard::class)->assessed($plan)) {
                AssessCompetitorGuardJob::dispatch($plan->id);
            }
        }
    }

    public function refreshKeywordInsights(): void
    {
        // render() re-evaluates get(); also re-attempt the keyword dispatch
        // (throttled) so a competitor request that wasn't ready at step-6 entry
        // still fires once competitors land.
        if (($plan = $this->plan()) !== null
            && \Illuminate\Support\Facades\Cache::add('content:kw-redispatch:'.$plan->id, 1, 20)) {
            PrepareContentKeywordInsightsJob::dispatch($plan->id);
        }
    }

    /** Keywords the client crossed out on the "best search terms" card. */
    public array $removedTerms = [];

    /** Cross out / restore a best-search-term pick (step 6). */
    public function toggleTerm(string $keyword): void
    {
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return;
        }
        $this->removedTerms = in_array($keyword, $this->removedTerms, true)
            ? array_values(array_diff($this->removedTerms, [$keyword]))
            : array_merge($this->removedTerms, [$keyword]);
    }

    public function toFirstArticles(): void
    {
        // Kept terms become confirmed keywords → the planner materializes one
        // article per term (1:1). Fail-soft: research still pending → 0 stored,
        // the step transition never blocks.
        if (($plan = $this->plan()) !== null
            && app(ContentKeywordInsights::class)->confirmTerms($plan, $this->removedTerms) > 0) {
            PlanContentTopicsJob::dispatch($plan->id);
        }
        $this->wizardStep = 7;
    }

    public function dropTopic(string $topicId): void
    {
        $this->plan()?->topics()->whereKey($topicId)
            ->whereIn('status', [ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED, ContentTopic::STATUS_FAILED])
            ->update(['status' => ContentTopic::STATUS_SKIPPED]);
    }

    protected function guessBrand(?Website $website): string
    {
        if ($website === null) {
            return '';
        }

        return Str::of((string) $website->domain)->before('.')->replace('-', ' ')->title()->value();
    }

    /** Topics generated so far, for the "first articles" step. */
    protected function draftTopics()
    {
        $plan = $this->plan();
        if ($plan === null) {
            return collect();
        }

        return $plan->topics()
            ->whereIn('status', [
                ContentTopic::STATUS_SUGGESTED, ContentTopic::STATUS_APPROVED,
                ContentTopic::STATUS_RESEARCHING, ContentTopic::STATUS_WRITING,
                ContentTopic::STATUS_SCORING, ContentTopic::STATUS_REVISING,
                ContentTopic::STATUS_READY, ContentTopic::STATUS_FAILED,
            ])
            ->orderBy('position')
            ->limit(12)
            ->get(['id', 'title', 'target_keyword', 'keyword_volume', 'source']);
    }

    /**
     * The `$wizard` view bag the shared partial consumes on steps 5/6/7
     * (competitor authority, keyword research, first-article previews).
     */
    protected function wizardViewData(): array
    {
        $insights = null;
        $generating = false;
        $needsReportGen = false;
        $hasOverrides = false;

        if ($this->wizardStep === 5 && ($w = $this->website()) !== null) {
            $svc = app(ContentSetupInsights::class);
            $rawInsights = $svc->competitorAuthority($w);
            $needsReportGen = $rawInsights === null;
            $plan4 = $this->plan();
            $insights = $plan4 !== null ? $svc->withOverrides($rawInsights, $plan4) : $rawInsights;
            $generating = $needsReportGen && $insights === null && $svc->isGenerating($w);
            $overrides = (array) ($plan4?->competitor_overrides ?? []);
            $hasOverrides = ! empty($overrides['added']) || ! empty($overrides['removed']);
        }

        $keywords = null;
        $keywordStatus = [];
        $guard = null;
        if ($this->wizardStep === 6 && ($plan5 = $this->plan()) !== null) {
            $kwSvc = app(ContentKeywordInsights::class);
            $keywords = $kwSvc->get($plan5);
            if ($keywords === null) {
                $keywordStatus = $kwSvc->researchStatus($plan5);
            }
            // Competitor-mention guard card — shown here, not on the
            // competitors step, same reasoning as ContentCalendar (see
            // infra/content-autopilot/README.md, 2026-07-22).
            $guard = app(CompetitorMentionGuard::class)->stateFor($plan5);
        }

        return [
            'guard' => $guard,
            'draftTopics' => $this->wizardStep >= 7 ? $this->draftTopics() : collect(),
            'insights' => $insights,
            'generating' => $generating,
            'needsReportGen' => $needsReportGen,
            'hasOverrides' => $hasOverrides,
            'keywords' => $keywords,
            'keywordStatus' => $keywordStatus,
            'hasWebsite' => $this->website() !== null,
        ];
    }
}
