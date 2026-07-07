<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * An anonymous, no-signup SEO audit run from the marketing landing page.
 *
 * Unlike {@see PageAuditReport} (which is keyed to a Website + GSC data), a
 * guest audit is identified only by an unguessable {@see $token} and is
 * produced from a user-supplied URL + keyword. The free audit runs in lite
 * mode and skips the paid Serper/Lighthouse stages — see
 * {@see \App\Services\PageAuditService::auditGuest()}.
 */
class GuestPageAudit extends Model
{
    use HasUlids;
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'token',
        'url',
        'keyword',
        'serp_gl',
        'name',
        'locale',
        'status',
        'http_status',
        'response_time_ms',
        'result',
        'error_message',
        'primary_keyword',
        'primary_keyword_source',
        'ip',
        'email',
    ];

    protected $casts = [
        'result' => 'array',
        'http_status' => 'int',
        'response_time_ms' => 'int',
    ];

    /** Bind {token} route params to this model instead of the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * Create a queued guest audit. The {@see \App\Jobs\RunGuestPageAudit} job
     * runs the actual work.
     */
    public static function start(string $url, string $keyword, ?string $ip = null, ?string $gl = null, ?string $email = null, ?string $name = null): self
    {
        return self::query()->create([
            'token' => (string) Str::uuid(),
            'url' => mb_substr($url, 0, 700),
            'keyword' => mb_substr(trim($keyword), 0, 200),
            'serp_gl' => $gl,
            'email' => $email !== null && trim($email) !== '' ? mb_substr(trim($email), 0, 255) : null,
            'name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
            'locale' => app()->getLocale(),
            'status' => self::STATUS_QUEUED,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
        ]);
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'error_message' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $outcome  The array returned by PageAuditService::auditGuest().
     */
    public function markCompleted(array $outcome): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'result' => is_array($outcome['result'] ?? null) ? $outcome['result'] : null,
            'http_status' => $outcome['http_status'] ?? null,
            'response_time_ms' => $outcome['response_time_ms'] ?? null,
            'primary_keyword' => isset($outcome['primary_keyword']) && is_string($outcome['primary_keyword'])
                ? mb_substr($outcome['primary_keyword'], 0, 200) : null,
            'primary_keyword_source' => isset($outcome['primary_keyword_source']) && is_string($outcome['primary_keyword_source'])
                ? mb_substr($outcome['primary_keyword_source'], 0, 32) : null,
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $reason, ?int $httpStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'http_status' => $httpStatus,
            'error_message' => mb_substr($reason, 0, 1000),
        ])->save();
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

    /**
     * Adapter so the shared audit-report Blade partial — which reads
     * {@see PageAuditReport::$page} — can render a GuestPageAudit unchanged.
     */
    public function getPageAttribute(): string
    {
        return (string) $this->url;
    }
}
