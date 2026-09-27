<?php

namespace App\Jobs\Content;

use App\Models\ContentAeoQuestion;
use App\Models\ContentAeoRun;
use App\Models\Website;
use App\Services\Content\Aeo\AeoQuestionSource;
use App\Services\Content\Aeo\AeoVisibilityScorer;
use App\Services\Content\Aeo\BrandRecallProbe;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use App\Support\ShardContext;
use App\Support\ShardLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A week's AI-visibility probing for one website: seed the questions if the
 * list is empty, ask the models, then store the day's score.
 *
 * Cost is a handful of cents a year per site — one small completion per
 * question, charged through the content meter like every other call.
 */
class RunAeoProbesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(Queues::SYNC);
    }

    public function uniqueId(): string
    {
        return $this->websiteId;
    }

    public function handle(BrandRecallProbe $probe, AeoQuestionSource $source, AeoVisibilityScorer $scorer): void
    {
        if (ShardLock::websiteLocked($this->websiteId)) {
            $this->release(60);

            return;
        }
        app(ShardContext::class)->forWebsite($this->websiteId);

        $website = Website::find($this->websiteId);
        if ($website === null || ! ContentAutopilotConfig::aeoProbesEnabled()) {
            return;
        }

        $quota = ContentAutopilotConfig::aeoQuestions();
        $source->seed($website, $quota);

        $questions = ContentAeoQuestion::query()
            ->where('website_id', $website->id)
            ->where('is_active', true)
            ->orderBy('last_run_at')   // never-run first
            ->limit($quota)
            ->get();

        $ok = 0;
        $failed = 0;
        foreach ($questions as $question) {
            try {
                $run = $probe->run($question, $website, ContentAeoRun::ENGINE_DEEPSEEK);
                $run->ok ? $ok++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('aeo.probe_error', [
                    'website_id' => $website->id,
                    'question_id' => $question->id,
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
            }
        }

        // A run where most probes failed would draw a cliff in the trend line
        // that has nothing to do with the client's visibility. Better to have
        // no point this week than a wrong one.
        if ($ok === 0 || $failed > $ok) {
            Log::warning('aeo.probe_run_unhealthy', [
                'website_id' => $website->id, 'ok' => $ok, 'failed' => $failed,
            ]);

            return;
        }

        $scorer->record($website);
        Log::info('aeo.probe_run', ['website_id' => $website->id, 'asked' => $ok, 'failed' => $failed]);
    }
}
