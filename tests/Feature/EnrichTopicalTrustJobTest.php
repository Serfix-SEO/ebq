<?php

namespace Tests\Feature;

use App\Jobs\EnrichTopicalTrustJob;
use App\Models\DomainMetric;
use App\Models\WebsiteReportSnapshot;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EnrichTopicalTrustJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeSnapshot(string $domain = 'gearshop.test'): WebsiteReportSnapshot
    {
        return WebsiteReportSnapshot::create([
            'normalized_domain' => $domain,
            'payload' => [
                'domain' => $domain,
                'top_referring_domains' => [
                    ['domain' => 'outdoorblog.test', 'rank' => 500, 'opr_score' => 5.0],
                    ['domain' => 'newsdaily.test', 'rank' => 700, 'opr_score' => 7.0],
                    ['domain' => 'linkfarm.test', 'rank' => 20, 'opr_score' => 0.5],
                ],
                'meta' => ['schema' => 2],
            ],
            'status' => 'ready',
            'fetched_at' => now(),
        ]);
    }

    private function fakeFetcher(): CrawlFetcher
    {
        $fetcher = Mockery::mock(CrawlFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturn([
            'ok' => true, 'blocked' => false, 'status' => 200, 'not_modified' => false,
            'body' => '<html><head><title>Outdoor gear reviews</title><meta name="description" content="Hiking gear tested."></head></html>',
        ]);

        return $fetcher;
    }

    private function fakeLlm(): LlmClient
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->once()->andReturn([
            'target_topic' => 'Shopping & E-commerce',
            'domains' => [
                ['domain' => 'outdoorblog.test', 'topic' => 'Sports & Recreation', 'relevant' => true],
                ['domain' => 'newsdaily.test', 'topic' => 'News & Media', 'relevant' => false],
                ['domain' => 'linkfarm.test', 'topic' => 'Directories & Link Sites', 'relevant' => false],
            ],
        ]);

        return $llm;
    }

    public function test_job_patches_topical_trust_section_and_caches_topics(): void
    {
        $snapshot = $this->makeSnapshot();

        (new EnrichTopicalTrustJob('gearshop.test'))->handle($this->fakeFetcher(), $this->fakeLlm());

        $tt = $snapshot->fresh()->payload['topical_trust'] ?? null;
        $this->assertNotNull($tt);
        $this->assertSame(33, $tt['relevant_pct']); // 1 of 3
        $this->assertSame(3, $tt['sample']);
        $this->assertSame('Sports & Recreation', $tt['rows'][0]['topic']);

        // Topics cached platform-wide — classified once, reused forever.
        $this->assertSame('News & Media', DomainMetric::where('domain', 'newsdaily.test')->value('topic'));
        $this->assertNotNull(DomainMetric::where('domain', 'outdoorblog.test')->value('topic_classified_at'));
    }

    public function test_llm_failure_leaves_section_absent(): void
    {
        $snapshot = $this->makeSnapshot();
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->andReturn(null);

        (new EnrichTopicalTrustJob('gearshop.test'))->handle($this->fakeFetcher(), $llm);

        $this->assertArrayNotHasKey('topical_trust', $snapshot->fresh()->payload);
    }

    public function test_pending_stub_is_replaced_on_success_and_cleared_on_failure(): void
    {
        // Success path: pending stub (stamped by GenerateWebsiteReport) is
        // replaced with the real section.
        $snapshot = $this->makeSnapshot();
        $payload = $snapshot->payload;
        $payload['topical_trust'] = ['pending' => true, 'queued_at' => now()->toIso8601String()];
        $snapshot->update(['payload' => $payload]);

        (new EnrichTopicalTrustJob('gearshop.test'))->handle($this->fakeFetcher(), $this->fakeLlm());

        $tt = $snapshot->fresh()->payload['topical_trust'];
        $this->assertArrayNotHasKey('pending', $tt);
        $this->assertNotEmpty($tt['topics']);

        // Failure path: stub is cleared so the UI spinner can't get stuck.
        $snapshot2 = $this->makeSnapshot('othershop.test');
        $payload2 = $snapshot2->payload;
        $payload2['topical_trust'] = ['pending' => true, 'queued_at' => now()->toIso8601String()];
        $snapshot2->update(['payload' => $payload2]);

        $failing = Mockery::mock(LlmClient::class);
        $failing->shouldReceive('completeJson')->andReturn(null);
        (new EnrichTopicalTrustJob('othershop.test'))->handle($this->fakeFetcher(), $failing);

        $this->assertArrayNotHasKey('topical_trust', $snapshot2->fresh()->payload);
    }

    public function test_batches_chain_until_all_referring_domains_classified(): void
    {
        config(['services.report.topical_trust.batch' => 3]);
        \Illuminate\Support\Facades\Queue::fake();

        $snapshot = WebsiteReportSnapshot::create([
            'normalized_domain' => 'bigsite.test',
            'payload' => [
                'domain' => 'bigsite.test',
                'top_referring_domains' => [
                    ['domain' => 'a.test'], ['domain' => 'b.test'], ['domain' => 'c.test'],
                    ['domain' => 'd.test'], ['domain' => 'e.test'],
                ],
                'meta' => ['schema' => 2],
            ],
            'status' => 'ready',
            'fetched_at' => now(),
        ]);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->once()->andReturn([
            'target_topic' => 'Technology & Internet',
            'domains' => [
                ['domain' => 'a.test', 'topic' => 'News & Media', 'relevant' => true],
                ['domain' => 'b.test', 'topic' => 'Technology & Internet', 'relevant' => true],
                ['domain' => 'c.test', 'topic' => 'Other', 'relevant' => false],
            ],
        ]);

        (new EnrichTopicalTrustJob('bigsite.test'))->handle($this->fakeFetcher(), $llm);

        // First batch landed: section shows partial coverage (3 of 5)…
        $tt = $snapshot->fresh()->payload['topical_trust'];
        $this->assertSame(3, $tt['sample']);
        $this->assertSame(5, $tt['total']);
        $this->assertSame(67, $tt['relevant_pct']); // 2 of 3

        // …and the job chained itself for the remaining domains.
        \Illuminate\Support\Facades\Queue::assertPushed(EnrichTopicalTrustJob::class, function ($job) {
            return $job->domain === 'bigsite.test' && $job->round === 1;
        });

        // Second round: classifies the remaining 2, merges, chain stops.
        $llm2 = Mockery::mock(LlmClient::class);
        $llm2->shouldReceive('completeJson')->once()->andReturn([
            'target_topic' => 'Technology & Internet',
            'domains' => [
                ['domain' => 'd.test', 'topic' => 'Directories & Link Sites', 'relevant' => false],
                ['domain' => 'e.test', 'topic' => 'Technology & Internet', 'relevant' => true],
            ],
        ]);
        \Illuminate\Support\Facades\Queue::fake();
        (new EnrichTopicalTrustJob('bigsite.test', 1))->handle($this->fakeFetcher(), $llm2);

        $tt = $snapshot->fresh()->payload['topical_trust'];
        $this->assertSame(5, $tt['sample']);
        $this->assertSame(5, $tt['total']);
        $this->assertSame(60, $tt['relevant_pct']); // 3 of 5
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_kill_switch_skips_everything(): void
    {
        config(['services.report.topical_trust.enabled' => false]);
        $snapshot = $this->makeSnapshot();

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldNotReceive('completeJson');

        (new EnrichTopicalTrustJob('gearshop.test'))->handle($this->fakeFetcher(), $llm);

        $this->assertArrayNotHasKey('topical_trust', $snapshot->fresh()->payload);
    }
}
