<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

class CustomPageAudit extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_PAGE_DETAIL = 'page_detail';

    /**
     * Audit triggered from the WP plugin's editor live-score endpoint.
     * Runs in lite mode (skips link checking + Serper benchmark) so the
     * editor spinner doesn't sit there for 90+ seconds.
     */
    public const SOURCE_LIVE_SCORE = 'live_score';

    /** Queued from the WordPress HQ “SEO Analysis” tab. */
    public const SOURCE_HQ_WP = 'hq_wp';

    /**
     * Audit queued from the dashboard striking-distance "Fix this keyword"
     * playbook. Runs in full mode (needs the Serper benchmark for competitor
     * titles + content-length comparison). Excluded from portal history so
     * playbook background runs don't clutter the Custom Audit list.
     */
    public const SOURCE_KEYWORD_FIX = 'keyword_fix';

    /**
     * Audit sources shown in EBQ portal history (custom audit page, etc.).
     * Excludes {@see SOURCE_LIVE_SCORE} — editor background runs only.
     *
     * @var list<string>
     */
    public const SOURCES_PORTAL_HISTORY = [
        self::SOURCE_CUSTOM,
        self::SOURCE_PAGE_DETAIL,
        self::SOURCE_HQ_WP,
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'website_id',
        'user_id',
        'source',
        'page_url',
        'page_url_hash',
        'target_keyword',
        'serp_sample_gl',
        'page_audit_report_id',
        'status',
        'error_message',
        'queued_at',
        'started_at',
        'finished_at',
        'attempts',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempts' => 'int',
    ];

    /**
     * Enqueue a new audit. Called from the UI; the job runs the actual work.
     */
    public static function queue(
        string $websiteId,
        string $userId,
        string $pageUrl,
        string $targetKeyword,
        ?string $serpSampleGl,
        string $source = self::SOURCE_CUSTOM,
    ): self {
        $kw = trim($targetKeyword);
        $gl = is_string($serpSampleGl) ? strtolower(trim($serpSampleGl)) : null;
        if ($gl !== null && (strlen($gl) !== 2 || ! ctype_alpha($gl))) {
            $gl = null;
        }

        return self::query()->create([
            'website_id' => $websiteId,
            'user_id' => $userId,
            'source' => in_array($source, [self::SOURCE_CUSTOM, self::SOURCE_PAGE_DETAIL, self::SOURCE_LIVE_SCORE, self::SOURCE_HQ_WP, self::SOURCE_KEYWORD_FIX], true)
                ? $source
                : self::SOURCE_CUSTOM,
            'page_url' => $pageUrl,
            'page_url_hash' => hash('sha256', $pageUrl),
            'target_keyword' => $kw !== '' ? mb_substr($kw, 0, 200) : '',
            'serp_sample_gl' => $gl,
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
            'attempts' => 0,
        ]);
    }

    /**
     * Log a *synchronously-completed* audit run. Used by the legacy "Audit this page"
     * flow on PageDetail that still runs PageAuditService inline. The queued flow
     * uses queue() + markCompleted() instead — do not call both.
     */
    public static function recordRun(
        string $websiteId,
        string $userId,
        string $pageUrlAsAudited,
        PageAuditReport $report,
        ?string $targetKeyword,
        string $source,
    ): self {
        $kw = $targetKeyword !== null ? trim($targetKeyword) : '';
        $status = $report->status === 'completed' ? self::STATUS_COMPLETED : self::STATUS_FAILED;

        return self::query()->create([
            'website_id' => $websiteId,
            'user_id' => $userId,
            'source' => in_array($source, [self::SOURCE_CUSTOM, self::SOURCE_PAGE_DETAIL, self::SOURCE_LIVE_SCORE, self::SOURCE_HQ_WP, self::SOURCE_KEYWORD_FIX], true)
                ? $source
                : self::SOURCE_CUSTOM,
            'page_url' => $pageUrlAsAudited,
            'page_url_hash' => hash('sha256', $pageUrlAsAudited),
            'target_keyword' => $kw !== '' ? mb_substr($kw, 0, 200) : '',
            'serp_sample_gl' => self::serpSampleGlFromReportResult($report),
            'page_audit_report_id' => $report->id,
            'status' => $status,
            'error_message' => $report->error_message,
            'queued_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'attempts' => 1,
        ]);
    }

    /**
     * Look for an already-queued-or-running audit for the same (website, url, user).
     * Returning a row means we should *not* queue a duplicate and paid-API-spend twice.
     */
    public static function findActiveFor(string $websiteId, string $pageUrl, string $userId): ?self
    {
        return self::query()
            ->where('website_id', $websiteId)
            ->where('user_id', $userId)
            ->where('page_url_hash', hash('sha256', $pageUrl))
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
            'error_message' => null,
        ])->save();
    }

    public function markCompleted(PageAuditReport $report): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'page_audit_report_id' => $report->id,
            'serp_sample_gl' => self::serpSampleGlFromReportResult($report) ?? $this->serp_sample_gl,
            'finished_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $reason, ?PageAuditReport $report = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'page_audit_report_id' => $report?->id ?? $this->page_audit_report_id,
            'finished_at' => now(),
            'error_message' => mb_substr($reason, 0, 1000),
        ])->save();
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    /** User-initiated audits for portal lists (custom audit, HQ API history). */
    public function scopePortalHistory(Builder $q): Builder
    {
        return $q->whereIn('source', self::SOURCES_PORTAL_HISTORY);
    }

    public function portalSourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_HQ_WP => 'WordPress',
            self::SOURCE_PAGE_DETAIL => 'Pages',
            default => 'EBQ',
        };
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function canRetry(): bool
    {
        return $this->isFailed();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pageAuditReport(): BelongsTo
    {
        return $this->belongsTo(PageAuditReport::class);
    }

    /**
     * Google {@code gl} used for the Serper snapshot (user-chosen or inferred), for list/history display.
     */
    public static function serpSampleGlFromReportResult(PageAuditReport $report): ?string
    {
        $result = is_array($report->result) ? $report->result : null;
        $pl = is_array($result['page_locale'] ?? null) ? $result['page_locale'] : null;
        if (! is_array($pl)) {
            return null;
        }
        foreach (['serp_gl_user_chosen', 'serp_gl_effective'] as $key) {
            if (! isset($pl[$key]) || ! is_string($pl[$key])) {
                continue;
            }
            $c = strtolower(trim($pl[$key]));
            if (strlen($c) === 2 && ctype_alpha($c)) {
                return $c;
            }
        }

        return null;
    }
}
