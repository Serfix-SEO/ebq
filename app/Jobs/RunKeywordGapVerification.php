<?php

namespace App\Jobs;

use App\Models\KeywordGapAnalysis;
use App\Services\Competitive\KeywordGapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Verifies a Keyword Gap Analysis's Missing bucket against the live SERP —
 * confirming the competitor actually ranks and capturing real positions. The
 * fan-out lives in {@see KeywordGapService::verify}.
 *
 * Unique per analysis so a double-dispatch can't double-bill SERP; tries=1 so a
 * partial result is kept rather than re-running the whole (paid) batch.
 */
class RunKeywordGapVerification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 1800;

    public function __construct(public readonly string $analysisId, public readonly ?string $bucket = null)
    {
        $this->onQueue(\App\Support\Queues::INTERACTIVE);
    }

    public function uniqueId(): string
    {
        return 'keyword-gap-verify:'.$this->analysisId;
    }

    public function handle(KeywordGapService $service): void
    {
        $service->verify($this->analysisId, $this->bucket);
    }

    public function failed(?\Throwable $e): void
    {
        $analysis = KeywordGapAnalysis::find($this->analysisId);
        if ($analysis !== null && $analysis->verify_status === KeywordGapAnalysis::VERIFY_STATUS_VERIFYING) {
            $analysis->forceFill([
                'verify_status' => KeywordGapAnalysis::VERIFY_STATUS_FAILED,
                'verify_error' => 'Verification timed out. Please try again.',
            ])->save();
        }
    }
}
