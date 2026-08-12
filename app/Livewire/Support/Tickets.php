<?php

namespace App\Livewire\Support;

use App\Mail\SupportTicketActivity;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /support — the customer's ticket list + "new ticket" form. User-scoped
 * (like Billing/Profile): tickets are per account, not per website; the
 * currently selected website is stored on the ticket as soft triage context.
 */
class Tickets extends Component
{
    use WithPagination;

    public bool $showCreate = false;

    public string $subject = '';

    public string $message = '';

    public function create(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $this->validate([
            'subject' => 'required|string|max:200',
            'message' => 'required|string|min:10|max:10000',
        ], [
            'message.min' => __('Tell us a little more so we can help — at least a sentence.'),
        ], ['subject' => __('subject'), 'message' => __('message')]);

        // A stuck-and-frustrated user mashing submit should not create ten
        // identical tickets.
        $key = 'support-ticket:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('subject', __('Too many new tickets — please wait a bit or add to an existing one.'));

            return;
        }
        RateLimiter::hit($key, 3600);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'website_id' => session('current_website_id') ?: null,
            'subject' => trim($this->subject),
            'status' => SupportTicket::STATUS_OPEN,
            'last_reply_at' => now(),
        ]);
        $msg = $ticket->messages()->create([
            'user_id' => $user->id,
            'is_admin' => false,
            'body' => trim($this->message),
        ]);

        $this->notifyAdmins($ticket, $msg, isNew: true);

        $this->redirectRoute('support.show', ['ticket' => $ticket->id]);
    }

    private function notifyAdmins(SupportTicket $ticket, $message, bool $isNew): void
    {
        try {
            $admins = User::query()->where('is_admin', true)->pluck('email')->filter()->values();
            if ($admins->isNotEmpty()) {
                Mail::to($admins->all())->send(new SupportTicketActivity($ticket, $message, $isNew));
            }
        } catch (\Throwable $e) {
            Log::warning('Support ticket admin notification failed: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.support.tickets', [
            'tickets' => SupportTicket::query()
                ->where('user_id', Auth::id())
                ->withCount('messages')
                ->orderByDesc('last_reply_at')
                ->paginate(15),
        ]);
    }
}
