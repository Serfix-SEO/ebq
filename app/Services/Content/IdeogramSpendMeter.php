<?php

namespace App\Services\Content;

use App\Services\Spend\MonthlySpendMeter;

/**
 * Monthly circuit-breaker for Ideogram image-generation spend. Exhausted =>
 * GenerateArticleImagesJob is skipped and articles publish without images
 * (never blocks the pipeline). Admin-only visibility.
 */
class IdeogramSpendMeter extends MonthlySpendMeter
{
    protected function prefix(): string
    {
        return 'ideogram:spend:';
    }

    protected function capConfigKey(): string
    {
        return 'services.ideogram.monthly_cap_usd';
    }
}
