<?php

namespace App\Services\Content;

use App\Services\Spend\MonthlySpendMeter;

/**
 * Monthly circuit-breaker for Content Autopilot LLM spend (write/revise/
 * ideation calls), estimated from token usage × the provider's configured
 * $/token rates. Exhausted => the dispatcher stops claiming new topics until
 * next month (already in-flight topics finish); calendar dates simply shift.
 * Admin-only visibility — clients see topics as "Scheduled".
 */
class ContentLlmSpendMeter extends MonthlySpendMeter
{
    /**
     * Conservative flat estimates per pipeline call (completeJson does not
     * surface token usage, and UsageMeter's activity rows are per-user, not
     * global). Deliberately rounded UP versus observed DeepSeek costs so the
     * breaker trips early, never late.
     */
    public const EST_WRITE_USD = 0.04;

    public const EST_REVISE_USD = 0.02;

    public const EST_IDEATE_USD = 0.01;

    protected function prefix(): string
    {
        return 'content:llm:spend:';
    }

    protected function capConfigKey(): string
    {
        return 'services.content_autopilot.llm_monthly_cap_usd';
    }
}
