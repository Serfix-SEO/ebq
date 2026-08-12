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
            'reply' => 'required|string|min:2|max:10000',
        ], [], ['reply' => __('reply')]);

        $msg = $ticket->messages()->create([
            'user_id' => (string) Auth::id(),
            'is_admin' => false,
            'body' => trim($this->reply),
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
