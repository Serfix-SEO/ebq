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
        if (preg_match('/<[a-z][^>]*>/i', $body) === 1) {
            return \App\Support\HtmlSanitizer::clean($body);
        }

        return nl2br(e($body));
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
