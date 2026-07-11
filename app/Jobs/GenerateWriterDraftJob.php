<?php

namespace App\Jobs;

use App\Exceptions\QuotaExceededException;
use App\Models\Website;
use App\Models\WriterProject;
use App\Services\WriterProjectService;
use App\Support\ShardContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async final step of the blog-post wizard. The generate call is a single
 * strict-mode writer LLM request (up to 20 sections / 16k output tokens,
 * 2–4 min with DeepSeek thinking enabled) — far too long to hold an HTTP
 * request open, especially through the WordPress plugin's proxy on shared
 * hosting. Both wizard UIs poll `writer_projects.generation_status`.
 *
 * tries=1: a retry would re-bill the full article's tokens; the user gets
 * a Try-again button instead. timeout stays under the redis retry_after
 * (1320s) so a running job is never re-delivered.
 */
class GenerateWriterDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 400;

    public function __construct(
        public readonly string $projectId,
        public readonly string $websiteId,
    ) {}

    public function handle(WriterProjectService $service): void
    {
        app(ShardContext::class)->forWebsite($this->websiteId);

        $project = WriterProject::find($this->projectId);
        $website = Website::find($this->websiteId);
        if ($project === null || $website === null) {
            Log::warning('GenerateWriterDraftJob: project or website gone', [
                'project' => $this->projectId,
                'website' => $this->websiteId,
            ]);
            return;
        }

        $project->generation_status = WriterProject::GEN_RUNNING;
        $project->generation_started_at = now();
        $project->generation_error = null;
        $project->save();

        try {
            $service->generate($project, $website);
        } catch (QuotaExceededException $e) {
            $this->markFailed($project, 'quota_exceeded');
            return;
        } catch (\Throwable $e) {
            Log::error('GenerateWriterDraftJob: generate threw', [
                'project' => $project->id,
                'msg' => $e->getMessage(),
            ]);
            $this->markFailed($project, 'generation_failed');
            return;
        }

        // generate() persists GEN_DONE / GEN_FAILED itself; nothing else
        // to do on success.
    }

    public function failed(?\Throwable $e): void
    {
        // Timeout / worker death after handle() started — make sure the
        // pollers aren't left on "running" forever.
        app(ShardContext::class)->forWebsite($this->websiteId);
        $project = WriterProject::find($this->projectId);
        if ($project !== null && $project->generation_status === WriterProject::GEN_RUNNING) {
            $this->markFailed($project, 'generation_timeout');
        }
    }

    private function markFailed(WriterProject $project, string $error): void
    {
        $project->generation_status = WriterProject::GEN_FAILED;
        $project->generation_error = $error;
        $project->save();
    }
}
