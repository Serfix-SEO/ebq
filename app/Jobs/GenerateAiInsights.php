<?php

namespace App\Jobs;

use App\Models\AiInsight;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class GenerateAiInsights implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(\App\Support\Queues::DEFAULT);
    }

    public function handle(): void
    {
        if (\App\Support\ShardLock::websiteLocked((string) $this->websiteId)) {
            $this->release(30);

            return;
        }
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::find($this->websiteId);
        // The website can be deleted while this job sits in the queue (a lead
        // cleaning up, an account closing). findOrFail turned that ordinary race
        // into a failed job — 6 of them during the 2026-08-16 backlog drain — and
        // a retry could never succeed. Nothing to sync is not a failure.
        if ($website === null) {
            return;
        }

        AiInsight::create([
            'website_id' => $website->id,
            'date' => Carbon::today(),
            'page' => '/',
            'payload' => ['summary' => 'AI insight generation placeholder for declining pages.'],
        ]);
    }
}
