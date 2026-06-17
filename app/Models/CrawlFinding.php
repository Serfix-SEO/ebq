<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesCrawlConnection;

/**
 * A single SEO issue surfaced by the crawler. See CrawlFinding catalog in
 * SiteIssueDetector. Severity + impact let every surface rank consistently.
 *
 * @property string $id
 * @property string $website_id
 * @property int|null $page_id
 * @property int|null $crawl_run_id
 * @property string $category
 * @property string $type
 * @property string $severity
 * @property float $impact
 * @property string $affected_url
 * @property string $affected_url_hash
 * @property array|null $detail
 * @property string $status
 */
class CrawlFinding extends Model
{
    use HasUlids;
    use UsesCrawlConnection;
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    public const CATEGORY_BROKEN_LINK = 'broken_link';
    public const CATEGORY_REDIRECT = 'redirect';
    public const CATEGORY_ONPAGE = 'onpage';
    public const CATEGORY_INDEXABILITY = 'indexability';
    public const CATEGORY_INTERNAL_LINKS = 'internal_links';
    public const CATEGORY_SITEMAP = 'sitemap';
    public const CATEGORY_SCHEMA = 'schema';
    public const CATEGORY_PERFORMANCE = 'performance';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_CRAWLABILITY = 'crawlability';

    /** Severity ordering for ranking (lower = more severe). */
    public const SEVERITY_RANK = [
        self::SEVERITY_CRITICAL => 0,
        self::SEVERITY_HIGH => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_LOW => 3,
    ];

    protected $fillable = [
        'website_id', 'crawl_site_id', 'page_id', 'crawl_run_id', 'category', 'type', 'severity',
        'impact', 'affected_url', 'affected_url_hash', 'detail', 'status',
        'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'impact' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', \App\Support\UrlNormalizer::normalize($url));
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function crawlSite(): BelongsTo
    {
        return $this->belongsTo(CrawlSite::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'page_id');
    }
}
