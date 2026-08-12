<?php

namespace App\Livewire\Support;

use App\Mail\SupportTicketActivity;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

/**
 * /support/{ticket} — one conversation. Ownership is re-checked on every
 * action (never trusted from the component property).
 */
class TicketThread extends Component
{
    public string $ticketId;

    public string $reply = '';

    public function mount(string $ticketId): void
    {
        $this->ticketId = $ticketId;
        abort_if($this->ticket() === null, 404);
    }

    public function send(): void
    {
        $ticket = $this->ticket();
        if ($ticket === null) {
            return;
        }

        $this->validate([
            'reply' => 'required|string|max:20000',
        ], [], ['reply' => __('reply')]);

        // The reply arrives as editor HTML — sanitize to the small whitelist
        // and validate length on the visible text, not the markup.
        $body = $this->sanitizedBody($this->reply);
        if ($body === null) {
            $this->addError('reply', __('Write a reply first.'));

            return;
        }

        $msg = $ticket->messages()->create([
            'user_id' => (string) Auth::id(),
            'is_admin' => false,
            'body' => $body,
        ]);
        // A client reply always puts the ball back in our court — including
        // re-opening a closed ticket.
        $ticket->forceFill([
            'status' => SupportTicket::STATUS_OPEN,
            'last_reply_at' => now(),
        ])->save();

        try {
            $admins = User::query()->where('is_admin', true)->pluck('email')->filter()->values();
            if ($admins->isNotEmpty()) {
                Mail::to($admins->all())->send(new SupportTicketActivity($ticket, $msg, isNew: false));
            }
        } catch (\Throwable $e) {
            Log::warning('Support ticket admin notification failed: '.$e->getMessage());
        }

        $this->reset('reply');
        $this->dispatch('support-editor-clear');
    }

    /** Sanitized message body, or null when nothing readable was written. */
    private function sanitizedBody(string $raw): ?string
    {
        $body = preg_match('/<[a-z][^>]*>/i', $raw) === 1
            ? \App\Support\HtmlSanitizer::clean($raw)
            : trim($raw);

        return mb_strlen(\App\Support\HtmlSanitizer::text($body)) >= 2 ? $body : null;
    }

    public function close(): void
    {
        $this->ticket()?->forceFill(['status' => SupportTicket::STATUS_CLOSED])->save();
    }

    private function ticket(): ?SupportTicket
    {
        return SupportTicket::query()
            ->where('user_id', Auth::id())
            ->whereKey($this->ticketId)
            ->first();
    }

    public function render()
    {
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);

        return view('livewire.support.ticket-thread', [
            'ticket' => $ticket,
            'messages' => $ticket->messages()->with('user:id,name,email')->get(),
        ]);
    }
}
