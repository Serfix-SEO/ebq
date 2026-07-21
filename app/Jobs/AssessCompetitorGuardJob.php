<?php

namespace App\Jobs;

use App\Models\ContentPlan;
use App\Services\Content\CompetitorMentionGuard;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Classify a plan's competitors for the mention guard (one flash LLM call).
 * Queued so the wizard's step transition never waits on an LLM; the guard
 * card on the competitors step polls and fills in when this lands.
 *
 * tries=1 — the guard also assesses lazily in ProduceContentArticleJob, so a
 * lost run self-heals before the first article; retrying here would re-bill.
 */
class AssessCompetitorGuardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public string $planId)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function uniqueId(): string
    {
        return 'assess-competitor-guard:'.$this->planId;
    }

    public function handle(CompetitorMentionGuard $guard): void
    {
        $plan = ContentPlan::query()->find($this->planId);
        if ($plan === null || $guard->assessed($plan)) {
            return;
        }

        $guard->assess($plan);
    }
}
