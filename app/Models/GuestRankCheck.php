<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Anonymous, no-signup keyword rank check launched from the public marketing
 * site. Identified only by an unguessable {@see $token}; the SERP lookup
 * happens in {@see \App\Jobs\RunGuestRankCheck} and the parsed result (the
 * target domain's position + top organic results) is stored in {@see $result}.
 */
class GuestRankCheck extends Model
{
    use HasUlids;
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'token', 'keyword', 'domain', 'country', 'status', 'result', 'error_message', 'ip', 'email', 'name', 'locale',
    ];

    protected $casts = [
        'result' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public static function start(
        string $keyword,
        string $domain,
        ?string $country = null,
        ?string $ip = null,
        ?string $email = null,
        ?string $name = null,
    ): self {
        return self::query()->create([
            'token' => (string) Str::uuid(),
            'keyword' => mb_substr(trim($keyword), 0, 200),
            'domain' => mb_substr(trim($domain), 0, 255),
            'country' => $country !== null && trim($country) !== '' ? mb_substr(strtolower(trim($country)), 0, 2) : null,
            'status' => self::STATUS_QUEUED,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'email' => $email !== null && trim($email) !== '' ? mb_substr(trim($email), 0, 255) : null,
            'name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
            'locale' => app()->getLocale(),
        ]);
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => self::STATUS_RUNNING, 'error_message' => null])->save();
    }

    /** @param array<string, mixed> $result */
    public function markCompleted(array $result): void
    {
        $this->forceFill(['status' => self::STATUS_COMPLETED, 'result' => $result, 'error_message' => null])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'error_message' => mb_substr($message, 0, 500)])->save();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
