<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }

    /**
     * Safe HTML for rendering ({!! !!} in blades and email bodies). Rich
     * messages are re-sanitized at read time (defense in depth); legacy
     * plain-text messages are escaped with newlines preserved.
     */
    public function bodyHtml(): string
    {
        $body = (string) $this->body;

        // Autolink AFTER escaping/sanitizing, never before: it works on
        // known-safe HTML and only touches text outside existing anchors.
        // People paste URLs instead of using the editor's link button, and
        // those used to render as dead text the client had to copy by hand.
        $safe = preg_match('/<[a-z][^>]*>/i', $body) === 1
            ? \App\Support\HtmlSanitizer::clean($body)
            : nl2br(e($body));

        return \App\Support\Autolink::apply($safe);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
