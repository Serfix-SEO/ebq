<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A threaded support conversation between a customer and the team.
 * Status is the "whose turn is it" tracker: open = awaiting our reply,
 * answered = awaiting the client, closed = done. A client reply on a
 * closed ticket re-opens it.
 */
class SupportTicket extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Bug reports ARE support tickets: every report becomes a thread the
     * client can follow up on. Timestamps mirror the report so backfilled
     * tickets sort truthfully. Idempotent via bug_reports.support_ticket_id.
     */
    public static function createFromBugReport(BugReport $report): self
    {
        $ticket = self::query()->create([
            'user_id' => $report->user_id,
            'website_id' => $report->website_id,
            'subject' => 'Bug report: '.\Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', $report->description) ?? $report->description), 120),
            'status' => self::STATUS_OPEN,
            'last_reply_at' => $report->created_at,
            'created_at' => $report->created_at,
        ]);
        $ticket->messages()->create([
            'user_id' => $report->user_id,
            'is_admin' => false,
            'body' => trim($report->description)."\n\n".__('Page').': '.$report->url,
            'created_at' => $report->created_at,
        ]);
        $report->forceFill(['support_ticket_id' => $ticket->id])->save();

        return $ticket;
    }
}
