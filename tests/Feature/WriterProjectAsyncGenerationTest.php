<?php

namespace Tests\Feature;

use App\Jobs\GenerateWriterDraftJob;
use App\Models\User;
use App\Models\Website;
use App\Models\WriterProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Async article generation (2026-07-11): POST generate queues
 * GenerateWriterDraftJob and returns 202; both wizard UIs poll
 * generate-status until the job lands.
 */
class WriterProjectAsyncGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ai_writer is tier-gated — the trial plan (every user's default)
        // includes it in the seeded plan set. The global kill-switch map
        // defaults ai_writer to FALSE on a fresh database, so flip it on
        // like prod's settings row does.
        $this->seed(\Database\Seeders\PlanSeeder::class);
        \App\Models\Setting::set('global_feature_flags', ['ai_writer' => true]);
    }

    private function projectFor(User $user): array
    {
        $website = Website::factory()->create(['user_id' => $user->id]);
        $project = WriterProject::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'title' => 'Test post',
            'focus_keyword' => 'testing',
            'step' => WriterProject::STEP_SUMMARY,
            'brief' => ['sections' => [['h2' => 'One']], 'paa' => [], 'gaps' => []],
        ]);

        return [$website, $project];
    }

    public function test_generate_queues_job_and_returns_202(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$website, $project] = $this->projectFor($user);

        $res = $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->postJson(route('ai-studio.writer-projects.generate', $project->external_id));

        $res->assertStatus(202)->assertJsonPath('generation.status', 'queued');
        Queue::assertPushed(GenerateWriterDraftJob::class, 1);
        $this->assertSame('queued', $project->fresh()->generation_status);
    }

    public function test_generate_does_not_double_queue_while_running(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$website, $project] = $this->projectFor($user);
        $project->update(['generation_status' => WriterProject::GEN_RUNNING, 'generation_started_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->postJson(route('ai-studio.writer-projects.generate', $project->external_id))
            ->assertStatus(202)
            ->assertJsonPath('generation.status', 'running');

        Queue::assertNothingPushed();
    }

    public function test_status_endpoint_reports_and_ships_project_when_done(): void
    {
        $user = User::factory()->create();
        [$website, $project] = $this->projectFor($user);
        $project->update([
            'generation_status' => WriterProject::GEN_DONE,
            'generated_html' => '<h2>One</h2><p>Body.</p>',
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->getJson(route('ai-studio.writer-projects.generate.status', $project->external_id))
            ->assertOk()
            ->assertJsonPath('generation.status', 'done')
            ->assertJsonPath('project.generated_html', '<h2>One</h2><p>Body.</p>');
    }

    public function test_stale_running_row_is_self_healed_to_failed(): void
    {
        $user = User::factory()->create();
        [$website, $project] = $this->projectFor($user);
        $project->update([
            'generation_status' => WriterProject::GEN_RUNNING,
            'generation_started_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->getJson(route('ai-studio.writer-projects.generate.status', $project->external_id))
            ->assertOk()
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.error', 'generation_timeout');
    }

    public function test_failed_job_marks_project_failed(): void
    {
        $user = User::factory()->create();
        [$website, $project] = $this->projectFor($user);
        $project->update(['generation_status' => WriterProject::GEN_RUNNING, 'generation_started_at' => now()]);

        (new GenerateWriterDraftJob($project->id, (string) $website->id))->failed(new \RuntimeException('worker died'));

        $fresh = $project->fresh();
        $this->assertSame(WriterProject::GEN_FAILED, $fresh->generation_status);
        $this->assertSame('generation_timeout', $fresh->generation_error);
    }
}
