<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\ContentKeywordInsights;
use App\Services\Content\HumanizerService;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression (prod 2026-07-21): a serfix.io article recommended Semrush — a
 * direct product competitor — because the writer has no idea a brand is a
 * competitor unless told. The guard classifies the plan's competitors against
 * what the client sells, auto-enables blocking when it matters, and enforces
 * it deterministically through the same lint → revise hard gate as the style
 * contract.
 */
class CompetitorMentionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        // The guard now fetches homepage context per candidate domain — never
        // let tests touch the network. Individual tests re-bind their own
        // stub as needed. (Mockery mocks skip the constructor, so
        // SafeHttpGuard's live DNS never runs.)
        $this->instance(CrawlFetcher::class, tap(\Mockery::mock(CrawlFetcher::class), function ($m) {
            $m->shouldReceive('fetch')
                ->andReturn(['ok' => false, 'status' => null, 'body' => '', 'error' => 'stubbed'])
                ->byDefault();
        }));
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Website, 2: ContentPlan} */
    private function planWithGuard(array $guard = [], array $toggles = []): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'billing_covered_at' => now(),
            'business_description' => 'An SEO platform selling audits, rank tracking and content tools.',
            'offerings' => ['sell' => ['SEO audits', 'Rank tracking'], 'dont_sell' => []],
            'competitor_guard' => $guard ?: null,
            'toggles' => $toggles,
        ]);

        return [$user, $website, $plan];
    }

    /** A classifier that returns a fixed verdict, tracking whether it was called. */
    private function fakeLlm(?array $verdict): LlmClient
    {
        return new class($verdict) implements LlmClient
        {
            /** @var list<array> every $messages array completeJson received */
            public array $captured = [];

            public function __construct(private readonly ?array $verdict) {}

            public function isAvailable(): bool
            {
                return $this->verdict !== null;
            }

            public function complete(array $messages, array $options = []): array
            {
                return ['ok' => true, 'content' => '', 'model' => 'fake',
                    'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0]];
            }

            public function completeJson(array $messages, array $options = []): ?array
            {
                $this->captured[] = $messages;

                return $this->verdict;
            }

            public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
            {
                return ['ok' => true, 'decoded' => null, 'content' => '', 'model' => 'fake',
                    'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0], 'tool_calls' => []];
            }
        };
    }

    private const ASSESSED_GUARD = [
        'assessed_at' => '2026-07-21T12:00:00+00:00',
        'harmful' => true,
        'reason' => 'Competitors sell the same tooling.',
        'auto' => [
            ['brand' => 'semrush', 'domain' => 'semrush.com', 'reason' => 'direct competitor'],
            ['brand' => 'ahrefs', 'domain' => 'ahrefs.com', 'reason' => 'direct competitor'],
        ],
        'references' => ['google.com'],
        'manual' => ['moz'],
        'removed' => ['ahrefs'],
    ];

    // ── the blocked list ────────────────────────────────────────────────

    public function test_terms_merge_auto_and_manual_minus_removed(): void
    {
        [, , $plan] = $this->planWithGuard(self::ASSESSED_GUARD);

        $this->assertSame(
            ['semrush', 'moz'],
            app(CompetitorMentionGuard::class)->terms($plan)
        );
    }

    /** A topic literally ABOUT a competitor may name it — "semrush alternatives" is a real article. */
    public function test_a_topic_targeting_the_competitor_is_exempt(): void
    {
        [, $website, $plan] = $this->planWithGuard(
            self::ASSESSED_GUARD,
            [CompetitorMentionGuard::TOGGLE => true],
        );
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Best Semrush Alternatives', 'target_keyword' => 'semrush alternatives',
            'status' => 'approved', 'scheduled_for' => now()->toDateString(),
        ]);

        $this->assertSame(['moz'], app(CompetitorMentionGuard::class)->termsForTopic($plan, $topic));
    }

    public function test_disabled_guard_blocks_nothing(): void
    {
        [, $website, $plan] = $this->planWithGuard(
            self::ASSESSED_GUARD,
            [CompetitorMentionGuard::TOGGLE => false],
        );
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'A topic', 'target_keyword' => 'seo basics',
            'status' => 'approved', 'scheduled_for' => now()->toDateString(),
        ]);

        $this->assertSame([], app(CompetitorMentionGuard::class)->termsForTopic($plan, $topic));
    }

    // ── lint enforcement ────────────────────────────────────────────────

    public function test_lint_flags_a_competitor_mention_and_a_link(): void
    {
        $issues = app(HumanizerService::class)->lint(
            '<p>You should use Semrush for a full audit.</p>'
            .'<p>See <a href="https://www.semrush.com/features">this page</a>.</p>',
            ['semrush'],
            ['semrush.com'],
        );

        $codes = array_column($issues, 'code');
        $this->assertContains('competitor_mentions', $codes);
        $issue = collect($issues)->firstWhere('code', 'competitor_mentions');
        $this->assertSame(2, $issue['count'], 'one text mention + one link');
        $this->assertStringContainsString('semrush', $issue['message']);
    }

    /** google.com is a reference for this client — its links must survive. */
    public function test_lint_ignores_reference_links_and_unlisted_brands(): void
    {
        $issues = app(HumanizerService::class)->lint(
            '<p>According to <a href="https://google.com/article">Google</a>, titles matter. Moz says so too.</p>',
            ['semrush'],
            ['semrush.com'],
        );

        $this->assertNotContains('competitor_mentions', array_column($issues, 'code'));
    }

    /** Word-boundary: "semrushing" (hypothetical) is not "semrush" — no false positives inside words. */
    public function test_lint_matches_whole_words_only(): void
    {
        $issues = app(HumanizerService::class)->lint('<p>The word unsemrushable is nonsense.</p>', ['semrush'], []);

        $this->assertNotContains('competitor_mentions', array_column($issues, 'code'));
    }

    // ── assessment ──────────────────────────────────────────────────────

    public function test_assess_classifies_blocks_vs_references_and_auto_enables(): void
    {
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['semrush.com', 'google.com']]]);

        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $this->fakeLlm([
            'harmful' => true,
            'reason' => 'Semrush sells exactly what you sell.',
            'domains' => [
                ['domain' => 'semrush.com', 'verdict' => 'block', 'brand' => 'Semrush', 'why' => 'direct competitor'],
                ['domain' => 'google.com', 'verdict' => 'reference', 'brand' => 'Google', 'why' => 'authority, not a rival'],
            ],
        ]));

        $plan->refresh();
        $guard = app(CompetitorMentionGuard::class);
        $this->assertTrue($guard->enabled($plan), 'harmful verdict auto-enables');
        $this->assertTrue($guard->autoEnabled($plan), 'the "we turned this on" banner marker is set');
        $this->assertSame(['semrush'], $guard->terms($plan));
        $this->assertSame(['semrush.com'], $guard->blockedDomains($plan));
        $this->assertSame(['google.com'], $plan->competitor_guard['references']);
    }

    /** A human's explicit OFF must survive any later re-assessment. */
    public function test_assess_never_overrides_an_explicit_human_decision(): void
    {
        [, , $plan] = $this->planWithGuard([], [CompetitorMentionGuard::TOGGLE => false]);
        $plan->update(['competitor_overrides' => ['added' => ['semrush.com']]]);

        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $this->fakeLlm([
            'harmful' => true, 'reason' => 'x',
            'domains' => [['domain' => 'semrush.com', 'verdict' => 'block', 'brand' => 'Semrush', 'why' => 'y']],
        ]));

        $this->assertFalse(app(CompetitorMentionGuard::class)->enabled($plan->fresh()));
    }

    /** No LLM → over-block by default: every competitor domain, brand derived from the domain. */
    public function test_assess_fails_soft_to_blocking_when_no_llm_is_available(): void
    {
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['semrush.com', 'www.ahrefs.co.uk']]]);

        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $this->fakeLlm(null));

        $plan->refresh();
        $guard = app(CompetitorMentionGuard::class);
        $this->assertTrue($guard->assessed($plan));
        $this->assertTrue($guard->enabled($plan));
        $this->assertSame(['semrush', 'ahrefs'], $guard->terms($plan));
    }

    /**
     * Caught live on staging: the classifier marked every domain a reference
     * yet still said harmful=true from abstract reasoning about hypothetical
     * rivals — auto-enabling the guard with ZERO blocked brands (a banner with
     * no chips). harmful must derive from the actual block list.
     */
    public function test_harmful_without_any_blocked_domain_does_not_auto_enable(): void
    {
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['semrush.com']]]);

        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $this->fakeLlm([
            'harmful' => true,   // the model's boolean lies
            'reason' => 'Hypothetical rivals might exist.',
            'domains' => [['domain' => 'semrush.com', 'verdict' => 'reference', 'brand' => 'Semrush', 'why' => 'not a rival here']],
        ]));

        $plan->refresh();
        $guard = app(CompetitorMentionGuard::class);
        $this->assertFalse($guard->enabled($plan), 'no blocked brand, nothing to enable');
        $this->assertFalse((bool) $plan->competitor_guard['harmful']);
        $this->assertSame([], $guard->terms($plan));
    }

    /** The model must not be able to invent domains we never gave it. */
    public function test_assess_drops_hallucinated_domains(): void
    {
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['semrush.com']]]);

        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $this->fakeLlm([
            'harmful' => true, 'reason' => 'x',
            'domains' => [
                ['domain' => 'semrush.com', 'verdict' => 'block', 'brand' => 'Semrush', 'why' => 'y'],
                ['domain' => 'made-up-rival.com', 'verdict' => 'block', 'brand' => 'MadeUp', 'why' => 'hallucinated'],
            ],
        ]));

        $this->assertSame(['semrush'], app(CompetitorMentionGuard::class)->terms($plan->fresh()));
    }

    // ── classifier prompt inputs (2026-07-22: justlife.com misfire) ──────

    /** Fixed verdict used by the prompt-input tests below. */
    private const PROMPT_VERDICT = [
        'harmful' => true, 'reason' => 'x',
        'domains' => [['domain' => 'justlife.com', 'verdict' => 'block', 'brand' => 'Justlife', 'why' => 'rival']],
    ];

    public function test_assess_prompt_carries_fetched_homepage_context(): void
    {
        $this->instance(CrawlFetcher::class, tap(\Mockery::mock(CrawlFetcher::class), function ($m) {
            $m->shouldReceive('fetch')->andReturn([
                'ok' => true, 'status' => 200,
                'body' => '<html><head><title>Justlife: Book Home Cleaning Services in Dubai</title>'
                    .'<meta name="description" content="On-demand cleaning and salon services in the UAE."></head></html>',
            ])->byDefault();
        }));
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['justlife.com']]]);

        $llm = $this->fakeLlm(self::PROMPT_VERDICT);
        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $llm);

        $prompt = $llm->captured[0][1]['content'];
        $this->assertStringContainsString('Justlife: Book Home Cleaning Services in Dubai', $prompt);
        $this->assertStringContainsString('On-demand cleaning', $prompt);
    }

    public function test_assess_prompt_marks_client_added_domains(): void
    {
        [, $website, $plan] = $this->planWithGuard();
        $this->seedCompetitorCache($website, ['thryv.com']);
        $plan->update(['competitor_overrides' => ['added' => ['justlife.com']]]);

        $llm = $this->fakeLlm(self::PROMPT_VERDICT);
        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $llm);

        $prompt = $llm->captured[0][1]['content'];
        $this->assertStringContainsString('justlife.com', $prompt);
        $this->assertStringContainsString('(added by the client as their competitor)', $prompt);
        // The auto-discovered directory must NOT carry the marker.
        $this->assertMatchesRegularExpression('/- thryv\.com(?! .*added by the client)/', $prompt);
    }

    public function test_assess_survives_context_fetch_failure(): void
    {
        $this->instance(CrawlFetcher::class, tap(\Mockery::mock(CrawlFetcher::class), function ($m) {
            $m->shouldReceive('fetch')->andThrow(new \RuntimeException('boom'))->byDefault();
        }));
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['justlife.com']]]);

        $llm = $this->fakeLlm(self::PROMPT_VERDICT);
        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $llm);

        $this->assertTrue(app(CompetitorMentionGuard::class)->assessed($plan->fresh()));
        $this->assertStringContainsString('- justlife.com', $llm->captured[0][1]['content']);
    }

    public function test_context_fetch_is_cached_between_assessments(): void
    {
        $this->instance(CrawlFetcher::class, tap(\Mockery::mock(CrawlFetcher::class), function ($m) {
            $m->shouldReceive('fetch')->once()->andReturn([
                'ok' => true, 'status' => 200,
                'body' => '<html><head><title>Justlife Home Services</title></head></html>',
            ]);
        }));
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['justlife.com']]]);

        $guard = app(CompetitorMentionGuard::class);
        $first = $this->fakeLlm(self::PROMPT_VERDICT);
        $guard->assess($plan->fresh(), $first);
        $second = $this->fakeLlm(self::PROMPT_VERDICT);
        $guard->assess($plan->fresh(), $second);

        // Mockery's ->once() fails the test if a second fetch fired; both
        // prompts still carry the title (second served from cache).
        $this->assertStringContainsString('Justlife Home Services', $first->captured[0][1]['content']);
        $this->assertStringContainsString('Justlife Home Services', $second->captured[0][1]['content']);
    }

    /** Prompt-regression guard for the bias flip: unknowns default to block. */
    public function test_prompt_rules_flip_the_bias_toward_blocking(): void
    {
        [, , $plan] = $this->planWithGuard();
        $plan->update(['competitor_overrides' => ['added' => ['justlife.com']]]);

        $llm = $this->fakeLlm(self::PROMPT_VERDICT);
        app(CompetitorMentionGuard::class)->assess($plan->fresh(), $llm);

        $prompt = $llm->captured[0][1]['content'];
        $this->assertStringContainsString('classify it "block"', $prompt);
        $this->assertStringNotContainsString('NOT necessarily', $prompt);
    }

    // ── producer wiring ─────────────────────────────────────────────────

    public function test_the_writer_prompt_carries_the_brand_rule(): void
    {
        [, $website, $plan] = $this->planWithGuard(
            self::ASSESSED_GUARD,
            [CompetitorMentionGuard::TOGGLE => true],
        );
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'How To Audit Your Site', 'target_keyword' => 'site audit guide',
            'status' => 'approved', 'scheduled_for' => now()->toDateString(),
        ]);

        $producer = app(ContentArticleProducer::class);
        $method = (new \ReflectionClass($producer))->getMethod('templateInstructions');
        $method->setAccessible(true);
        $rules = $method->invoke($producer, $plan, $topic);

        $this->assertStringContainsString('STRICT BRAND RULE', $rules);
        $this->assertStringContainsString('semrush', $rules);
        $this->assertStringContainsString('moz', $rules);
    }

    // ── UI ──────────────────────────────────────────────────────────────

    public function test_the_settings_card_shows_terms_and_the_auto_enabled_banner(): void
    {
        [$user, $website] = $this->planWithGuard(
            self::ASSESSED_GUARD + ['auto_enabled_at' => '2026-07-21T12:00:00+00:00'],
            [CompetitorMentionGuard::TOGGLE => true],
        );
        ContentPlan::query()->where('website_id', $website->id)->update(['status' => ContentPlan::STATUS_ACTIVE]);

        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->assertSee(__('Competitor mention protection'))
            ->assertSee('semrush')
            ->assertSee('moz')
            ->assertSee(__('You can switch this off or edit the list at any time — also later, in Content Settings.'));
    }

    public function test_toggling_off_is_recorded_as_a_human_decision(): void
    {
        [$user, $website, $plan] = $this->planWithGuard(
            self::ASSESSED_GUARD + ['auto_enabled_at' => '2026-07-21T12:00:00+00:00'],
            [CompetitorMentionGuard::TOGGLE => true],
        );
        $plan->update(['status' => ContentPlan::STATUS_ACTIVE]);

        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('toggleCompetitorGuard');

        $plan->refresh();
        $guard = app(CompetitorMentionGuard::class);
        $this->assertFalse($guard->enabled($plan));
        $this->assertFalse($guard->autoEnabled($plan), 'human click clears the auto marker');
        $this->assertTrue($guard->decided($plan), 'later re-assessment must not flip it back');
    }

    public function test_terms_can_be_added_and_removed_from_the_card(): void
    {
        [$user, $website, $plan] = $this->planWithGuard(
            self::ASSESSED_GUARD,
            [CompetitorMentionGuard::TOGGLE => true],
        );
        $plan->update(['status' => ContentPlan::STATUS_ACTIVE]);

        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('newBlockedTerm', 'SE Ranking')
            ->call('addBlockedTerm')
            ->call('removeBlockedTerm', 'semrush');

        $terms = app(CompetitorMentionGuard::class)->terms($plan->refresh());
        $this->assertContains('se ranking', $terms);
        $this->assertNotContains('semrush', $terms);
    }

    // ── keyword-research competitor pick ────────────────────────────────

    /** @param list<string> $domains */
    private function seedCompetitorCache(Website $website, array $domains): void
    {
        Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 0, 'my_authority' => null,
            'competitors' => array_map(static fn ($d) => [
                'domain' => $d, 'referring_domains' => null, 'backlinks' => null,
                'authority' => null, 'da' => null, 'pa' => null,
            ], $domains),
            'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());
    }

    /** @return list<string> */
    private function researchPick(ContentPlan $plan): array
    {
        $svc = app(ContentKeywordInsights::class);
        $m = (new \ReflectionClass($svc))->getMethod('topCompetitorDomains');
        $m->setAccessible(true);

        return $m->invoke($svc, $plan, $plan->website, 1);
    }

    /**
     * The screenshot bug: a cleaning company's single research slot went to
     * thryv.com — a directory that merely OUTRANKS them — because the pick was
     * authority order over the raw report list. A classified product rival
     * further down the list must win.
     */
    public function test_research_analyzes_the_classified_rival_not_the_directory(): void
    {
        [, $website, $plan] = $this->planWithGuard([
            'assessed_at' => '2026-07-21T12:00:00+00:00',
            'harmful' => true, 'reason' => 'x',
            'auto' => [['brand' => 'sparklehome', 'domain' => 'sparklehome.com', 'reason' => 'cleaning rival']],
            'references' => ['thryv.com'],
        ]);
        $this->seedCompetitorCache($website, ['thryv.com', 'sparklehome.com']);

        $this->assertSame(['sparklehome.com'], $this->researchPick($plan));
    }

    /** A classified reference must never consume the research slot, even with no rival found. */
    public function test_research_skips_references_when_nothing_is_blocked(): void
    {
        [, $website, $plan] = $this->planWithGuard([
            'assessed_at' => '2026-07-21T12:00:00+00:00',
            'harmful' => false, 'reason' => '',
            'auto' => [],
            'references' => ['thryv.com'],
        ]);
        $this->seedCompetitorCache($website, ['thryv.com', 'unclassified-rival.com']);

        $this->assertSame(['unclassified-rival.com'], $this->researchPick($plan));
    }

    /** No assessment yet → the raw order still works (research never stalls on the guard). */
    public function test_research_falls_back_to_raw_order_when_unassessed(): void
    {
        [, $website, $plan] = $this->planWithGuard();
        $this->seedCompetitorCache($website, ['thryv.com', 'sparklehome.com']);

        $this->assertSame(['thryv.com'], $this->researchPick($plan));
    }

    /** Removing a competitor on the wizard step must remove it from research too. */
    public function test_research_respects_manual_competitor_removal(): void
    {
        [, $website, $plan] = $this->planWithGuard();
        $this->seedCompetitorCache($website, ['thryv.com', 'sparklehome.com']);
        $plan->update(['competitor_overrides' => ['removed' => ['thryv.com']]]);

        $this->assertSame(['sparklehome.com'], $this->researchPick($plan->fresh()));
    }
}
