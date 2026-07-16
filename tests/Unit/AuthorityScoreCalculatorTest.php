<?php

namespace Tests\Unit;

use App\Services\Reports\AuthorityScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pure fixture tests — no DB, no Laravel app. Seeds are injected via the
 * constructor so config() is never touched.
 */
class AuthorityScoreCalculatorTest extends TestCase
{
    private const SEEDS = ['wikipedia.org', 'nytimes.com', 'mit.edu'];

    private function calc(): AuthorityScoreCalculator
    {
        return new AuthorityScoreCalculator(self::SEEDS);
    }

    /** A representative full-report payload (assemble() shape). */
    private function fullPayload(): array
    {
        return [
            'domain' => 'example.com',
            'popularity' => ['score' => 4.7, 'rank' => 1639043, 'history' => []],
            'gauges' => [
                'domain_authority' => 41,
                'page_authority' => 38,
                'spam_score' => 3,
                'authority_score' => 47, // DataForSEO rank 470 / 10
            ],
            'totals' => [
                'backlinks' => 24318,
                'referring_domains' => 1842,
                'referring_ips' => 1650,
                'referring_subnets' => 1400,
            ],
            'ratios' => ['dofollow_pct' => 62, 'active_pct' => 94],
            'top_referring_domains' => [
                ['domain' => 'en.wikipedia.org', 'rank' => 890, 'backlinks' => 1, 'opr_score' => 9.1],
                ['domain' => 'nytimes.com', 'rank' => 850, 'backlinks' => 2, 'opr_score' => 8.8],
                ['domain' => 'smallblog.net', 'rank' => 120, 'backlinks' => 5, 'opr_score' => 2.1],
                ['domain' => 'spamdir.xyz', 'rank' => 10, 'backlinks' => 44, 'opr_score' => 0.4],
            ],
            'backlinks' => [
                ['url_from' => 'https://a.com/x', 'url_to' => 'https://example.com/', 'anchor' => 'x', 'dofollow' => true, 'rank' => 600, 'opr_score' => 6.0],
            ],
            'competitors' => [
                ['domain' => 'rival.com', 'opr_score' => 5.0, 'popularity_rank' => 900000],
            ],
            'profile_details' => [
                'tlds' => [
                    ['label' => 'com', 'count' => 900],
                    ['label' => 'org', 'count' => 60],
                    ['label' => 'edu', 'count' => 30],
                    ['label' => 'gov', 'count' => 10],
                ],
            ],
            'meta' => ['schema' => 2],
        ];
    }

    public function test_citation_score_exact_value(): void
    {
        $scores = $this->calc()->scores($this->fullPayload());

        // v2 weights, cc absent → renormalize over .70:
        // (47*.35 + 47*.20 + 54.42*.15) / .70 = 48.59 → 49
        $this->assertSame(49, $scores['citation']);
        $this->assertSame(AuthorityScoreCalculator::VERSION, $scores['version']);
    }

    public function test_trust_score_exact_value(): void
    {
        $scores = $this->calc()->scores($this->fullPayload());

        // v2, harmonic absent → renormalize over .85:
        // spam 97(.25) + strong-share 100(.10) + dofollow 77.5(.15)
        // + diversity 100(.15) + tld 40(.10) + seeds 40(.10)
        // = 68.875/.85 = 81.03 → 81 raw; ceiling = 49+10 = 59
        $this->assertSame(59, $scores['trust']);
    }

    public function test_trust_ceiling_not_applied_when_below(): void
    {
        $payload = $this->fullPayload();
        $payload['gauges']['spam_score'] = 65;
        $payload['ratios']['dofollow_pct'] = 20;
        $payload['top_referring_domains'] = [
            ['domain' => 'a.xyz', 'rank' => 10, 'opr_score' => 0.5],
            ['domain' => 'b.xyz', 'rank' => 12, 'opr_score' => 0.4],
            ['domain' => 'c.xyz', 'rank' => 9, 'opr_score' => 0.3],
        ];
        $payload['totals']['referring_ips'] = 40;
        $payload['totals']['referring_subnets'] = 12;
        $payload['profile_details']['tlds'] = [['label' => 'xyz', 'count' => 500], ['label' => 'com', 'count' => 100]];

        $scores = $this->calc()->scores($payload);

        // spam 35(.25)=8.75, strong 0(.10)=0, dofollow 25(.15)=3.75,
        // diversity avg(40/1842,12/1842)=.0141/0.8=.0176→1.76(.15)=0.265,
        // tld 0(.10)=0, seeds 0(.10)=0 → 12.76/.85 → 15; below ceiling 59.
        $this->assertSame(15, $scores['trust']);
        $this->assertLessThan($scores['citation'] + 10, $scores['trust']);
    }

    public function test_partial_payload_gets_citation_only(): void
    {
        $partial = [
            'domain' => 'young-site.com',
            'popularity' => ['score' => 2.4, 'rank' => 6200000, 'history' => []],
            'gauges' => ['domain_authority' => null, 'page_authority' => null, 'spam_score' => null, 'authority_score' => null],
            'totals' => ['backlinks' => null, 'referring_domains' => null, 'referring_ips' => null, 'referring_subnets' => null],
            'ratios' => ['dofollow_pct' => null, 'active_pct' => null],
            'top_referring_domains' => [],
            'backlinks' => [],
            'competitors' => [],
            'profile_details' => null,
            'meta' => ['schema' => 2, 'partial' => true],
        ];

        $scores = $this->calc()->scores($partial);

        $this->assertSame(24, $scores['citation']); // OPR only: 2.4*10
        $this->assertNull($scores['trust']);        // <2 trust components
    }

    public function test_all_missing_yields_nulls(): void
    {
        $scores = $this->calc()->scores(['domain' => 'x.com']);

        $this->assertNull($scores['citation']);
        $this->assertNull($scores['trust']);
    }

    public function test_citation_weights_renormalize_when_component_missing(): void
    {
        $payload = $this->fullPayload();
        $payload['gauges']['authority_score'] = null; // drop rank component

        $scores = $this->calc()->scores($payload);

        // (47*.35 + 54.42*.15) / .50 = 49.23 → 49
        $this->assertSame(49, $scores['citation']);
    }

    public function test_row_citation_blend_and_renormalization(): void
    {
        $calc = $this->calc();

        // cc absent → renormalize over .65: (91*.40 + 55*.25)/.65 = 77.15
        $this->assertSame(77, $calc->rowCitation(9.1, 550));
        // cc present, all three: 91*.40 + 80*.35 + 55*.25 = 78.15
        $this->assertSame(78, $calc->rowCitation(9.1, 550, 80.0));
        $this->assertSame(91, $calc->rowCitation(9.1, null)); // opr only
        $this->assertSame(55, $calc->rowCitation(null, 550)); // rank only
        $this->assertNull($calc->rowCitation(null, null));
        $this->assertSame(77, $calc->rowScore(9.1, 550)); // deprecated alias
    }

    public function test_row_trust_from_cc_with_seed_floor(): void
    {
        $calc = $this->calc();

        $this->assertSame(90, $calc->rowTrust(90.0, 'randomblog.net'));
        $this->assertSame(85, $calc->rowTrust(null, 'en.wikipedia.org'));  // seed floor
        $this->assertSame(85, $calc->rowTrust(20.0, 'nytimes.com'));       // floor beats low cc
        $this->assertSame(92, $calc->rowTrust(92.0, 'wikipedia.org'));     // cc above floor wins
        $this->assertNull($calc->rowTrust(null, 'unknown-site.net'));      // nothing known → "—"
    }

    public function test_cc_webgraph_components_feed_both_scores(): void
    {
        $payload = $this->fullPayload();
        // Stashed by ClientReportService::scored() when the CC sidecar exists.
        $payload['cc'] = ['citation_pct' => 80.0, 'trust_pct' => 90.0];

        $scores = $this->calc()->scores($payload);

        // Citation, all four components: 47*.35 + 80*.30 + 47*.20 + 54.42*.15
        // = 16.45 + 24 + 9.4 + 8.163 = 58.01 → 58
        $this->assertSame(58, $scores['citation']);
        // Trust, all seven: spam 97(.25) + harmonic 90(.15) + share 100(.10)
        // + dofollow 77.5(.15) + diversity 100(.15) + tld 40(.10) + seeds 40(.10)
        // = 82.375 → 82 raw; ceiling 58+10 = 68
        $this->assertSame(68, $scores['trust']);
    }

    public function test_topictrust_score_from_topical_section(): void
    {
        $payload = $this->fullPayload();
        $payload['topical_trust'] = ['sample' => 20, 'total' => 20, 'relevant_pct' => 50, 'topics' => [['topic' => 'Other', 'count' => 20]]];

        $out = $this->calc()->augment($payload);

        // TT = TS * (0.4 + 0.6*0.50) = 59 * 0.7 = 41.3 → 41
        $this->assertSame(59, $out['scores']['trust']);
        $this->assertSame(41, $out['scores']['topical']);

        // Refreshes on read even when scores are already current-version:
        // batches raise relevant_pct after the stamp → topical follows.
        $out['topical_trust']['relevant_pct'] = 100;
        $again = $this->calc()->augment($out);
        $this->assertSame(59, $again['scores']['topical']); // == TS at 100% relevance

        // No topical data → null (renders "—").
        $none = $this->calc()->augment($this->fullPayload());
        $this->assertNull($none['scores']['topical']);
    }

    public function test_augment_adds_scores_and_row_cs(): void
    {
        $out = $this->calc()->augment($this->fullPayload());

        $this->assertIsInt($out['scores']['citation']);
        $this->assertIsInt($out['scores']['trust']);
        $this->assertSame(AuthorityScoreCalculator::VERSION, $out['scores']['version']);

        $this->assertArrayHasKey('cs', $out['top_referring_domains'][0]);
        $this->assertArrayHasKey('cs', $out['backlinks'][0]);
        $this->assertArrayHasKey('cs', $out['competitors'][0]);
        $this->assertSame(50, $out['competitors'][0]['cs']); // OPR-only row

        // Per-row Trust: wikipedia row hits the seed floor even without cc
        // data; non-seed rows without cc stay null (render as "—").
        $this->assertSame(85, $out['top_referring_domains'][0]['ts']);
        $this->assertNull($out['backlinks'][0]['ts']);

        // With cc percentiles stashed (sidecar available), ts follows harmonic.
        $payload = $this->fullPayload();
        $payload['top_referring_domains'][2]['cc_trust'] = 41.5;
        $payload['top_referring_domains'][2]['cc_citation'] = 38.0;
        $withCc = $this->calc()->augment($payload);
        $this->assertSame(42, $withCc['top_referring_domains'][2]['ts']);
    }

    public function test_augment_is_idempotent(): void
    {
        $calc = $this->calc();
        $once = $calc->augment($this->fullPayload());
        $twice = $calc->augment($once);

        $this->assertSame($once, $twice);
    }

    public function test_augment_recomputes_older_version(): void
    {
        $payload = $this->fullPayload();
        $payload['scores'] = ['citation' => 1, 'trust' => 1, 'version' => 0];

        $out = $this->calc()->augment($payload);

        $this->assertNotSame(1, $out['scores']['citation']);
    }

    public function test_seed_matching_handles_subdomains_and_dedupes(): void
    {
        $payload = $this->fullPayload();
        // Two wikipedia subdomains → ONE distinct seed match (20 pts, not 40).
        $payload['top_referring_domains'] = [
            ['domain' => 'en.wikipedia.org', 'rank' => 890, 'opr_score' => 9.1],
            ['domain' => 'de.wikipedia.org', 'rank' => 870, 'opr_score' => 9.0],
            ['domain' => 'smallblog.net', 'rank' => 120, 'opr_score' => 2.1],
        ];
        $payload['gauges']['spam_score'] = null;
        $payload['ratios']['dofollow_pct'] = null;
        $payload['totals']['referring_ips'] = null;
        $payload['totals']['referring_subnets'] = null;
        $payload['profile_details'] = null;

        // Components left: strong share (2/3 → clamp 100, w .10) + seeds (20, w .10)
        // = (10 + 2) / .20 = 60 raw → ceiling 49+10 = 59.
        $scores = $this->calc()->scores($payload);

        $this->assertSame(59, $scores['trust']);
    }

    public function test_no_referring_rows_skips_seed_and_share_components(): void
    {
        $payload = $this->fullPayload();
        $payload['top_referring_domains'] = [];

        $scores = $this->calc()->scores($payload);

        // Remaining: spam 97(.25), dofollow 77.5(.15), diversity 100(.15), tld 40(.10)
        // = (24.25+11.625+15+4)/.65 = 84.42 → 84 raw → ceiling 59
        $this->assertSame(59, $scores['trust']);
    }
}
