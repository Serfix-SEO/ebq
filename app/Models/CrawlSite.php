<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One shared crawl per normalized domain. Many users' Website rows
 * (websites.crawl_site_id) point at the same CrawlSite, so a domain is crawled
 * once — at the MAX page cap among its subscribers (effective_cap) — and every
 * subscriber reads the same crawl through a cap-limited view (WebsitePage.value_rank).
 *
 * Domain-level crawl signals that used to live on `websites` move here:
 * crawl_protection / crawl_protection_at and the sitemap-lastmod trust counters.
 *
 * @property string $id
 * @property string $normalized_domain
 * @property int $effective_cap
 * @property int|null $health_score
 * @property string $status
 * @property string|null $crawl_protection
 * @property \Illuminate\Support\Carbon|null $crawl_protection_at
 * @property int $sitemap_lastmod_true
 * @property int $sitemap_lastmod_false
 * @property int $subscriber_count
 */
class CrawlSite extends Model
{
    use HasUlids;
    protected $fillable = [
        'crawl_node_id',
        'normalized_domain', 'effective_cap', 'health_score', 'status',
        'crawl_protection', 'crawl_protection_at',
        'sitemap_lastmod_true', 'sitemap_lastmod_false', 'subscriber_count',
        'last_crawl_started_at', 'last_crawl_finished_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_cap' => 'integer',
            'health_score' => 'integer',
            'crawl_protection_at' => 'datetime',
            'sitemap_lastmod_true' => 'integer',
            'sitemap_lastmod_false' => 'integer',
            'subscriber_count' => 'integer',
            'last_crawl_started_at' => 'datetime',
            'last_crawl_finished_at' => 'datetime',
        ];
    }

    /**
     * Canonical key for a domain: lowercase, no scheme, no `www.`, no path/trailing
     * slash. `https://www.Basepaws.com/` and `basepaws.com` both collapse to
     * `basepaws.com`. Mirrors the host normalization in Website::isAuditUrlForThisSite.
     */
    public static function normalizeDomain(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d) ?? $d; // drop scheme
        $d = preg_replace('#/.*$#', '', $d) ?? $d;        // drop path/trailing slash
        $d = preg_replace('/^www\./', '', $d) ?? $d;      // drop leading www.
        $d = rtrim($d, '.');                              // drop trailing dot(s)

        return $d;
    }

    /** Canonical homepage URL for the crawl frontier (one scheme+host per site). */
    public function homepageUrl(): string
    {
        return 'https://'.$this->normalized_domain;
    }

    /** Websites (across users) that share this crawl. */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class, 'crawl_site_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class, 'crawl_site_id');
    }

    public function internalLinks(): HasMany
    {
        return $this->hasMany(WebsiteInternalLink::class, 'crawl_site_id');
    }

    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class, 'crawl_site_id');
    }

    public function crawlFindings(): HasMany
    {
        return $this->hasMany(CrawlFinding::class, 'crawl_site_id');
    }

    public function latestRun(): ?CrawlRun
    {
        return $this->crawlRuns()->latest('started_at')->first();
    }

    /** The in-flight crawl run for this site, if any (bounded to a sane 6h window). */
    public function runningCrawl(): ?CrawlRun
    {
        return $this->crawlRuns()
            ->whereIn('status', [CrawlRun::STATUS_RUNNING, CrawlRun::STATUS_FINALIZING])
            ->where('started_at', '>=', now()->subHours(6))
            ->latest('started_at')
            ->first();
    }

    public function isCrawling(): bool
    {
        return $this->runningCrawl() !== null;
    }

    public function isCrawlProtected(): bool
    {
        return in_array($this->crawl_protection, ['cloudflare', 'blocked'], true);
    }

    /** Whether this domain's sitemap <lastmod> reliably predicts content change. */
    public function sitemapLastmodTrusted(): bool
    {
        $total = $this->sitemap_lastmod_true + $this->sitemap_lastmod_false;
        $min = (int) config('crawler.lastmod_min_sample', 20);
        $ratio = (float) config('crawler.lastmod_trust_ratio', 0.3);

        return $total >= $min && ($this->sitemap_lastmod_true / max(1, $total)) >= $ratio;
    }

    /** Confirmed-meaningless (always-bumping) lastmod — skip early recrawls. */
    public function sitemapLastmodConfirmedUntrusted(): bool
    {
        $total = $this->sitemap_lastmod_true + $this->sitemap_lastmod_false;
        $min = (int) config('crawler.lastmod_min_sample', 20);
        $ratio = (float) config('crawler.lastmod_trust_ratio', 0.3);

        return $total >= $min && ($this->sitemap_lastmod_true / max(1, $total)) < $ratio;
    }

    /**
     * Recompute effective_cap = max crawl page cap across subscribers. Returns the
     * new value. Used when a subscriber joins/leaves so the shared crawl is deep
     * enough for the highest-tier user.
     */
    public function recomputeEffectiveCap(): int
    {
        $cap = $this->websites()->get()
            ->map(fn (Website $w): int => $w->crawlPageCap())
            ->max() ?? 0;

        $this->forceFill(['effective_cap' => (int) $cap, 'subscriber_count' => $this->websites()->count()])->save();

        return (int) $cap;
    }
}
