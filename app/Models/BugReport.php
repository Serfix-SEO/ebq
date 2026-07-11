<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app bug report ("Report a bug" top-bar button). Screenshot (optional)
 * lives on the local disk under bug-reports/ and is served to admins only
 * via Admin\BugReportController::screenshot().
 */
class BugReport extends Model
{
    use HasUlids;

    public const STATUS_NEW = 'new';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'user_id',
        'website_id',
        'url',
        'description',
        'screenshot_path',
        'user_agent',
        'viewport',
        'status',
        'resolution_note',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
