<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Anonymous, no-signup PageSpeed test launched from the public marketing
 * site. Identified only by an unguessable {@see $token}; the heavy
 * Lighthouse runs happen in {@see \App\Jobs\RunGuestPageSpeedStrategy} (one
 * per strategy) and the parsed mobile+desktop report is stored in {@see $result}.
 */
class GuestPageSpeed extends Model
{
    use HasUlids;
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'token', 'url', 'status', 'result', 'error_message', 'ip', 'email', 'name', 'locale',
    ];

    protected $casts = [
        'result' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public static function start(string $url, ?string $ip = null, ?string $email = null, ?string $name = null): self
    {
        return self::query()->create([
            'token' => (string) Str::uuid(),
            'url' => mb_substr($url, 0, 700),
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
