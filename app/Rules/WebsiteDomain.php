<?php

namespace App\Rules;

use App\Support\DomainName;
use App\Support\GiantDomains;
use App\Models\CrawlSite;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One validation rule for every field where a user types "your website".
 *
 * Before this existed the only rule was `['required','string','max:255']`, so
 * an email address, a sentence or a bare word all became crawlable websites.
 * Keep the three verdicts distinct — a visitor who typed their email needs to
 * be told that, not handed "invalid domain".
 */
class WebsiteDomain implements ValidationRule
{
    public function __construct(private bool $rejectGiants = true)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = trim((string) $value);

        if (DomainName::looksLikeEmail($raw)) {
            $fail(__('That looks like an email address. Enter your website address instead, like example.com.'))->translate();

            return;
        }

        $normalized = CrawlSite::normalizeDomain($raw);

        if (! DomainName::isValid($normalized)) {
            $fail(__('Enter a valid website address, like example.com.'))->translate();

            return;
        }

        if ($this->rejectGiants && GiantDomains::isGiant($normalized)) {
            $fail(__('That looks like a major platform, not your own website. Enter the site you own and can publish articles to.'))->translate();
        }
    }
}
