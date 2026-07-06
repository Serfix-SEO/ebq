<?php

namespace Tests\Feature;

use App\Jobs\RunCustomPageAudit;
use App\Models\CustomPageAudit;
use App\Models\PageAuditReport;
use App\Models\User;
use App\Models\Website;
use App\Services\PageAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression/coverage test for the content-hash re-audit gate (added
 * 2026-07-06, infra/audits/page-audit.md §Gotchas): a completed audit used
 * to be reused indefinitely unless WordPress's self-reported `modified` time
 * was newer than `audited_at`. ebq:recheck-audit-content independently
 * re-fetches and hashes the page to catch drift WordPress doesn't report.
 */
class RecheckAuditContentTest extends TestCase
{
    use RefreshDatabase;

    /** Bind a fake PageAuditService whose currentContentHash() is scripted per URL. */
    private function fakeHashService(array $hashesByUrl): void
    {
        $fake = new class($hashesByUrl) extends PageAuditService
        {
            public function __construct(private array $hashesByUrl)
            {
                // Intentionally skip the parent constructor — it needs real
                // SafeHttpGuard/ProxyPool instances this fake never uses.
            }

            public function currentContentHash(string $pageUrl): ?string
            {
                return $this->hashesByUrl[$pageUrl] ?? null;
            }
        };
        $this->app->instance(PageAuditService::class, $fake);
    }

    private function seedReport(Website $website, string $url, string $storedHash): PageAuditReport
    {
        return PageAuditReport::create([
            'website_id' => $website->id,
            'page' => $url,
            'page_hash' => hash('sha256', $url),
            'status' => 'completed',
            'audited_at' => now()->subDays(3),
            'result' => ['ok' => true],
            'content_hash' => $storedHash,
        ]);
    }

    public function test_unchanged_content_is_not_requeued(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $this->seedReport($website, 'https://example.com/same', 'hash-a');
        $this->fakeHashService(['https://example.com/same' => 'hash-a']);

        $this->artisan('ebq:recheck-audit-content')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_changed_content_queues_a_fresh_audit(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $this->seedReport($website, 'https://example.com/changed', 'hash-old');
        $this->fakeHashService(['https://example.com/changed' => 'hash-new']);

        $this->artisan('ebq:recheck-audit-content')->assertSuccessful();

        $this->assertDatabaseHas('custom_page_audits', [
            'website_id' => $website->id,
            'page_url' => 'https://example.com/changed',
            'source' => CustomPageAudit::SOURCE_CUSTOM,
        ]);
        Queue::assertPushed(RunCustomPageAudit::class, 1);
    }

    public function test_fetch_failure_is_skipped_not_treated_as_changed(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $this->seedReport($website, 'https://example.com/unreachable', 'hash-a');
        $this->fakeHashService([]); // no entry → currentContentHash() returns null

        $this->artisan('ebq:recheck-audit-content')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_dry_run_reports_but_does_not_queue(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $this->seedReport($website, 'https://example.com/changed-dry', 'hash-old');
        $this->fakeHashService(['https://example.com/changed-dry' => 'hash-new']);

        $this->artisan('ebq:recheck-audit-content --dry-run')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('custom_page_audits', ['website_id' => $website->id]);
    }
}
