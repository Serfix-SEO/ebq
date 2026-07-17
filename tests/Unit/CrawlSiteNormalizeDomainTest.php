<?php

namespace Tests\Unit;

use App\Models\CrawlSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrawlSiteNormalizeDomainTest extends TestCase
{
    #[DataProvider('domainCases')]
    public function test_normalize_domain_collapses_variants(string $input, string $expected): void
    {
        $this->assertSame($expected, CrawlSite::normalizeDomain($input));
    }

    public static function domainCases(): array
    {
        return [
            'plain' => ['basepaws.com', 'basepaws.com'],
            'www' => ['www.basepaws.com', 'basepaws.com'],
            'https' => ['https://basepaws.com', 'basepaws.com'],
            'https www trailing slash' => ['https://www.basepaws.com/', 'basepaws.com'],
            'http www path' => ['http://www.basepaws.com/about/team', 'basepaws.com'],
            'uppercase' => ['HTTPS://WWW.BasePaws.COM', 'basepaws.com'],
            'whitespace' => ['  basepaws.com  ', 'basepaws.com'],
            'trailing dot' => ['basepaws.com.', 'basepaws.com'],
            'subdomain kept' => ['blog.basepaws.com', 'blog.basepaws.com'],
            'www only stripped at start' => ['www.www-example.com', 'www-example.com'],
        ];
    }
}
