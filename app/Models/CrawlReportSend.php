<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Concerns\UsesTenantConnection;

/**
 * One row per crawl-issue summary report emailed from the admin Marketing panel.
 * Keeps the record of what was sent and to whom (the `summary` JSON is the exact
 * numbers + example errors that were in the email at send time).
 *
 * @property string $id
 * @property int|null $website_id
 * @property int|null $recipient_user_id
 * @property int|null $sent_by_user_id
 * @property string $to_email
 * @property string $subject
 * @property array $summary
 * @property string $status
 */
class CrawlReportSend extends Model
{
    use HasUlids;
    use UsesTenantConnection;
    protected $fillable = [
        'website_id', 'recipient_user_id', 'sent_by_user_id',
        'to_email', 'subject', 'summary', 'status',
    ];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
