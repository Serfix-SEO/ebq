<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesCrawlConnection;

/**
 * A directed internal-link edge for a website's link graph.
 * status='discovered' = a real edge found in the page HTML.
 * status='suggested'  = an AI-proposed new internal link (InternalLinkSuggester).
 *
 * @property string $id
 * @property string $website_id
 * @property int $from_page_id
 * @property int $to_page_id
 * @property string|null $anchor_text
 * @property string $status
 */
class WebsiteInternalLink extends Model
{
    use HasUlids;
    use UsesCrawlConnection;
    public const STATUS_DISCOVERED = 'discovered';
    public const STATUS_SUGGESTED = 'suggested';

    protected $fillable = [
        'website_id', 'crawl_site_id', 'from_page_id', 'to_page_id', 'anchor_text', 'status', 'discovered_at',
    ];

    protected function casts(): array
    {
        return [
            'discovered_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function crawlSite(): BelongsTo
    {
        return $this->belongsTo(CrawlSite::class);
    }

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'from_page_id');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'to_page_id');
    }
}
