<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesCrawlConnection;

/**
 * @property string $id
 * @property string $website_id
 * @property string $trigger
 * @property string $status
 * @property string|null $blocked_reason
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class CrawlRun extends Model
{
    use HasUlids;
    use UsesCrawlConnection;
    public const STATUS_RUNNING = 'running';
    // Crawl fetching is done; AnalyzeSiteJob is computing the graph/findings/score.
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABORTED = 'aborted';

    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_ON_CREATE = 'on_create';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_BACKFILL = 'backfill';
    public const TRIGGER_SITEMAP_DELTA = 'sitemap_delta';
    // A crawl_site reused/copied — reserved; shared-store may not emit this.
    public const TRIGGER_REUSED = 'reused';

    protected $fillable = [
        'website_id', 'crawl_site_id', 'trigger', 'status', 'started_at', 'finished_at',
        'pages_seen', 'pages_fetched', 'pages_304', 'pages_changed', 'pages_error',
        'findings_total', 'health_score', 'blocked_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages_seen' => 'integer',
            'pages_fetched' => 'integer',
            'pages_304' => 'integer',
            'pages_changed' => 'integer',
            'pages_error' => 'integer',
            'findings_total' => 'integer',
            'health_score' => 'integer',
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

    public function findings(): HasMany
    {
        return $this->hasMany(CrawlFinding::class);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_ABORTED && $this->blocked_reason !== null;
    }
}
