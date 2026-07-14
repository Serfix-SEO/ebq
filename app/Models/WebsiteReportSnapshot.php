<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared per-domain report cache row (network effect). Keyed by
 * `normalized_domain`; the `payload` holds the full rendered report data
 * (DataForSEO backlink profile + history + anchors + referring domains +
 * competitors + Moz DA/PA/Spam). Any user querying a domain reads the same
 * row. Freshness (when to re-fetch) is decided by {@see \App\Services\ReportFreshnessGate}.
 *
 * Cross-tenant shared cache — plain HasUlids on the central connection, like
 * {@see CompetitorBacklink}.
 */
class WebsiteReportSnapshot extends Model
{
    use HasUlids;

    protected $fillable = [
        'normalized_domain',
        'domain_authority',
        'page_authority',
        'spam_score',
        'rank',
        'referring_domains',
        'backlinks_total',
        'dataforseo_cost_usd',
        'payload',
        'status',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
            'dataforseo_cost_usd' => 'float',
        ];
    }

    /**
     * Normalize a raw domain/URL the same way the crawl subsystem does, so the
     * shared cache key can't be bypassed by a scheme / www / casing variant.
     */
    public static function normalizeDomain(string $raw): string
    {
        return CrawlSite::normalizeDomain($raw);
    }

    /**
     * Storage key for a domain. Sandbox (admin-only mock data) is namespaced so
     * it never collides with the shared production snapshot customers read.
     */
    public static function keyFor(string $domain, bool $sandbox = false): string
    {
        $normalized = self::normalizeDomain($domain);
        if ($normalized === '') {
            return '';
        }

        return $sandbox ? 'sbx:'.$normalized : $normalized;
    }

    /**
     * The stored snapshot for a domain, if one exists.
     */
    public static function forDomain(string $domain, bool $sandbox = false): ?self
    {
        $key = self::keyFor($domain, $sandbox);
        if ($key === '') {
            return null;
        }

        return self::query()->where('normalized_domain', $key)->first();
    }
}
