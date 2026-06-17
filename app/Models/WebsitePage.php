<?php

namespace App\Models;

use App\Support\UrlNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesCrawlConnection;

/**
 * One row per crawled URL on a website (distinct from page_audit_reports,
 * which is one row per audit run). Populated + refreshed by CrawlWebsitePagesJob.
 *
 * @property string $id
 * @property string $website_id
 * @property string $url
 * @property string $url_hash
 * @property string|null $title
 * @property int|null $http_status
 * @property string|null $meta_description
 * @property string|null $canonical_url
 * @property bool $is_indexable
 * @property string|null $robots_directives
 * @property string|null $redirect_target
 * @property string|null $content_hash
 * @property string|null $etag
 * @property string|null $last_modified_header
 * @property array|null $headings_json
 * @property int|null $word_count
 * @property int $internal_link_count
 * @property int $external_link_count
 * @property int $inbound_link_count
 * @property int|null $click_depth
 * @property bool $source_gsc
 * @property bool $source_sitemap
 * @property int|null $page_score
 */
class WebsitePage extends Model
{
    use HasUlids;
    use UsesCrawlConnection;
    protected $fillable = [
        'website_id', 'crawl_site_id', 'value_rank',
        'url', 'url_hash', 'title', 'http_status', 'meta_description',
        'canonical_url', 'is_indexable', 'robots_directives', 'redirect_target',
        'content_hash', 'etag', 'last_modified_header', 'content_length', 'word_count',
        'headings_json', 'seo_signals', 'body_text', 'internal_link_count', 'external_link_count',
        'inbound_link_count', 'click_depth', 'source_gsc', 'source_sitemap', 'page_score',
        'http_error', 'last_crawled_at', 'discovered_at', 'last_changed_at',
        'next_crawl_at', 'removed_at',
        'content_simhash', 'consecutive_unchanged', 'sitemap_lastmod', 'content_terms',
    ];

    protected function casts(): array
    {
        return [
            'headings_json' => 'array',
            'seo_signals' => 'array',
            'is_indexable' => 'boolean',
            'source_gsc' => 'boolean',
            'source_sitemap' => 'boolean',
            'http_status' => 'integer',
            'word_count' => 'integer',
            'internal_link_count' => 'integer',
            'external_link_count' => 'integer',
            'inbound_link_count' => 'integer',
            'click_depth' => 'integer',
            'page_score' => 'integer',
            'last_crawled_at' => 'datetime',
            'discovered_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'next_crawl_at' => 'datetime',
            'removed_at' => 'datetime',
            'consecutive_unchanged' => 'integer',
            'sitemap_lastmod' => 'datetime',
        ];
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', UrlNormalizer::normalize($url));
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function crawlSite(): BelongsTo
    {
        return $this->belongsTo(CrawlSite::class);
    }

    /** Internal links that originate from this page. */
    public function outboundLinks(): HasMany
    {
        return $this->hasMany(WebsiteInternalLink::class, 'from_page_id');
    }

    /** Internal links that point to this page. */
    public function inboundLinks(): HasMany
    {
        return $this->hasMany(WebsiteInternalLink::class, 'to_page_id');
    }

    public function scopeIndexable(Builder $query): Builder
    {
        return $query->where('is_indexable', true)->whereNull('removed_at');
    }

    /**
     * Pages that exist + are indexable but have no inbound internal links and
     * aren't listed in the sitemap (sitemap-listed pages are discoverable by
     * search engines, so they aren't actionable orphans). Mirrors the orphan_page
     * finding rule in SiteIssueDetector.
     */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query->indexable()
            ->whereNotNull('last_crawled_at')
            ->where('inbound_link_count', 0)
            ->where(function (Builder $q): void {
                $q->where('source_sitemap', false)->orWhereNull('source_sitemap');
            });
    }

    /** Pages due for (re)crawl: never crawled or past their next_crawl_at. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('next_crawl_at')->orWhere('next_crawl_at', '<=', now());
        })->whereNull('removed_at');
    }
}
