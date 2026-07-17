<?php

namespace Tests\Unit;

use App\Services\Reports\BacklinkSampleAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Pure fixtures — proves the local aggregations emit exactly what the paid
 * DataForSEO endpoints would (same keys, same sort order) for a complete
 * sample. See BacklinkSampleAggregator's header for when this is valid.
 */
class BacklinkSampleAggregatorTest extends TestCase
{
    private function sample(): array
    {
        // 5 live links: 2 from blog.alpha.test (rank 410), 2 from beta.test
        // (rank 120), 1 from gamma.test (rank 0 / missing).
        return [
            ['domain_from' => 'blog.alpha.test', 'domain_from_rank' => 410, 'url_to' => 'https://t.test/', 'anchor' => 'Target Co', 'dofollow' => true, 'first_seen' => '2025-03-10 08:00:00'],
            ['domain_from' => 'blog.alpha.test', 'domain_from_rank' => 410, 'url_to' => 'https://t.test/pricing', 'anchor' => 'pricing', 'dofollow' => false, 'first_seen' => '2024-11-02 09:00:00'],
            ['domain_from' => 'beta.test', 'domain_from_rank' => 120, 'url_to' => 'https://t.test/', 'anchor' => 'Target Co', 'dofollow' => true, 'first_seen' => '2025-01-05 10:00:00'],
            ['domain_from' => 'beta.test', 'domain_from_rank' => 120, 'url_to' => 'https://t.test/', 'anchor' => '', 'dofollow' => true, 'first_seen' => '2025-06-20 11:00:00'],
            ['domain_from' => 'gamma.test', 'url_to' => 'https://t.test/blog', 'anchor' => 'Target Co', 'dofollow' => false, 'first_seen' => '2026-02-14 12:00:00'],
        ];
    }

    public function test_referring_domains_groups_ranks_counts_and_sorts(): void
    {
        $rows = (new BacklinkSampleAggregator)->referringDomains($this->sample());

        $this->assertSame(['blog.alpha.test', 'beta.test', 'gamma.test'], array_column($rows, 'domain')); // rank desc
        $this->assertSame([410, 120, null], array_column($rows, 'rank'));
        $this->assertSame([2, 2, 1], array_column($rows, 'backlinks'));
        // Earliest first_seen per domain (blog.alpha.test has 2024-11 < 2025-03).
        $this->assertSame('2024-11-02 09:00:00', $rows[0]['first_seen']);
    }

    public function test_anchors_groups_dofollow_distinct_domains_and_sorts(): void
    {
        $rows = (new BacklinkSampleAggregator)->anchors($this->sample());

        $this->assertSame('Target Co', $rows[0]['anchor']); // 3 links — top by backlinks
        $this->assertSame(3, $rows[0]['backlinks']);
        $this->assertSame(3, $rows[0]['referring_domains']); // alpha, beta, gamma
        $this->assertSame(2, $rows[0]['dofollow']); // gamma's is nofollow
        // Empty anchor kept as its own row (image/naked links), like the endpoint.
        $anchors = array_column($rows, 'anchor');
        $this->assertContains('', $anchors);
        $this->assertContains('pricing', $anchors);
    }

    public function test_domain_pages_groups_by_target_url_and_sorts(): void
    {
        $rows = (new BacklinkSampleAggregator)->domainPages($this->sample());

        $this->assertSame('https://t.test/', $rows[0]['url']); // 3 links, 2 domains
        $this->assertSame(3, $rows[0]['backlinks']);
        $this->assertSame(2, $rows[0]['referring_domains']); // alpha + beta (beta twice = 1)
        $this->assertCount(3, $rows);
    }

    public function test_empty_sample_yields_empty_aggregates(): void
    {
        $agg = new BacklinkSampleAggregator;
        $this->assertSame([], $agg->referringDomains([]));
        $this->assertSame([], $agg->anchors([]));
        $this->assertSame([], $agg->domainPages([]));
    }
}
