<?php

namespace Tests\Feature;

use App\Models\DomainMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\DomainIntel\DomainMetricsRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainMetricsRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'domain' => 'client-site.test',
            'popularity' => ['score' => 4.7],
            'gauges' => ['spam_score' => 3, 'authority_score' => 47],
            'scores' => ['trust' => 41, 'citation' => 49, 'version' => 2],
            'top_referring_domains' => [
                ['domain' => 'en.wikipedia.org', 'rank' => 890, 'opr_score' => 9.1, 'cs' => 90],
                ['domain' => 'smallblog.net', 'rank' => 120, 'opr_score' => 2.1, 'cs' => 17],
            ],
            'competitors' => [
                ['domain' => 'rival.test', 'opr_score' => 5.0, 'cs' => 50],
            ],
        ];
    }

    public function test_record_report_upserts_main_referring_and_competitor_domains(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'client-site.test']);

        app(DomainMetricsRecorder::class)->recordReport('client-site.test', $this->payload());

        $main = DomainMetric::where('domain', 'client-site.test')->first();
        $this->assertNotNull($main);
        $this->assertSame('active', $main->tier); // owned website → active tier
        $this->assertSame(41, $main->trust_score);
        $this->assertSame(49, $main->citation_score);
        $this->assertSame(470, $main->dfs_rank);

        $wiki = DomainMetric::where('domain', 'en.wikipedia.org')->first();
        $this->assertSame('free', $wiki->tier);
        $this->assertTrue($wiki->is_seed); // registrable wikipedia.org is on the seed list
        $this->assertSame(90, $wiki->citation_score);

        $this->assertSame('free', DomainMetric::where('domain', 'rival.test')->value('tier'));
        $this->assertSame(4, DomainMetric::count());
    }

    public function test_second_report_increments_times_seen_and_never_erases_known_values(): void
    {
        $recorder = app(DomainMetricsRecorder::class);
        $recorder->recordReport('client-site.test', $this->payload());
        $firstSeen = DomainMetric::where('domain', 'en.wikipedia.org')->value('first_seen_at');

        // Second, sparser sighting of the same referring domain (nulls).
        $sparse = $this->payload();
        $sparse['top_referring_domains'] = [
            ['domain' => 'en.wikipedia.org', 'rank' => null, 'opr_score' => null, 'cs' => null],
        ];
        $sparse['competitors'] = [];
        $recorder->recordReport('client-site.test', $sparse);

        $wiki = DomainMetric::where('domain', 'en.wikipedia.org')->first();
        $this->assertSame(2, $wiki->times_seen);
        $this->assertSame(9.1, $wiki->opr_score);           // null did NOT erase
        $this->assertSame(90, $wiki->citation_score);       // null did NOT erase
        $this->assertEquals($firstSeen, $wiki->first_seen_at); // write-once
    }

    public function test_recorder_failure_never_throws(): void
    {
        // Table dropped mid-flight — recordReport must swallow the error.
        \Illuminate\Support\Facades\Schema::drop('domain_metric_history');
        \Illuminate\Support\Facades\Schema::drop('domain_metrics');

        app(DomainMetricsRecorder::class)->recordReport('client-site.test', $this->payload());

        $this->assertTrue(true); // reached without exception
    }
}
