<?php

namespace Tests\Unit;

use App\Services\Reports\CcDomainRanks;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests against a throwaway SQLite file built inline — never touches the
 * live sidecar at storage/app/cc-domain-ranks.sqlite.
 */
class CcDomainRanksTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = sys_get_temp_dir().'/cc-ranks-test-'.getmypid().'.sqlite';
        if (file_exists($this->db)) {
            unlink($this->db);
        }

        $pdo = new \PDO('sqlite:'.$this->db);
        $pdo->exec('CREATE TABLE ranks(domain TEXT PRIMARY KEY, harmonic INTEGER NOT NULL, pagerank INTEGER NOT NULL) WITHOUT ROWID');
        $pdo->exec('CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO meta VALUES ('total_domains','100000000')");
        $pdo->exec("INSERT INTO ranks VALUES
            ('wikipedia.org', 9, 12),
            ('example.com', 1000000, 2000000),
            ('tinyblog.net', 90000000, 95000000)");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->db)) {
            unlink($this->db);
        }
        parent::tearDown();
    }

    public function test_unavailable_when_file_missing(): void
    {
        $ranks = new CcDomainRanks('/nonexistent/nowhere.sqlite');

        $this->assertFalse($ranks->available());
        $this->assertNull($ranks->scoreFor('wikipedia.org'));
    }

    public function test_percentiles_scale_with_rank(): void
    {
        $ranks = new CcDomainRanks($this->db);

        $this->assertTrue($ranks->available());

        $wiki = $ranks->scoreFor('wikipedia.org');
        // trust: 100*(1-log10(9)/8)=88.1; citation: 100*(1-log10(12)/8)=86.5
        $this->assertSame(88.1, $wiki['trust_pct']);
        $this->assertSame(86.5, $wiki['citation_pct']);

        $mid = $ranks->scoreFor('example.com');
        $this->assertSame(25.0, $mid['trust_pct']);      // 100*(1-6/8)
        $this->assertSame(21.2, $mid['citation_pct']);   // 100*(1-log10(2e6)/8)

        $tail = $ranks->scoreFor('tinyblog.net');
        $this->assertLessThan(5, $tail['trust_pct']);
    }

    public function test_subdomain_falls_back_to_registrable(): void
    {
        $ranks = new CcDomainRanks($this->db);

        $this->assertNotNull($ranks->scoreFor('en.wikipedia.org'));
        $this->assertSame(
            $ranks->scoreFor('wikipedia.org'),
            $ranks->scoreFor('en.wikipedia.org'),
        );
    }

    public function test_unknown_domain_is_null_and_batch_skips_it(): void
    {
        $ranks = new CcDomainRanks($this->db);

        $this->assertNull($ranks->scoreFor('never-crawled.example'));

        $batch = $ranks->lookupMany(['wikipedia.org', 'never-crawled.example', 'example.com']);
        $this->assertCount(2, $batch);
        $this->assertArrayHasKey('wikipedia.org', $batch);
        $this->assertArrayHasKey('example.com', $batch);
    }
}
