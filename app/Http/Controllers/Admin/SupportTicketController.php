<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SupportTicketReplied;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * /admin/support — every ticket across the app. "open" is the work queue
 * (customer is waiting on us); replying flips a ticket to "answered" and
 * emails the customer. Admin-facing copy is English-only by convention.
 */
class SupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        $tickets = SupportTicket::query()
            ->with(['user:id,name,email', 'website:id,domain'])
            ->withCount('messages')
            ->when(
                in_array($status, [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_ANSWERED, SupportTicket::STATUS_CLOSED], true),
                fn ($q) => $q->where('status', $status),
            )
            ->orderByDesc('last_reply_at')
            ->paginate(40)
            ->withQueryString();

        $counts = SupportTicket::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('admin.support.index', [
            'tickets' => $tickets,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        return view('admin.support.show', [
            'ticket' => $ticket->load(['user:id,name,email', 'website:id,domain']),
            'messages' => $ticket->messages()->with('user:id,name,email')->get(),
            // Tickets born from a bug report carry extra context (page URL,
            // screenshot, viewport) on the report row.
            'bugReport' => \App\Models\BugReport::query()->where('support_ticket_id', $ticket->id)->first(),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:20000']);

        // Editor HTML → whitelist-sanitized; length checked on visible text.
        $body = preg_match('/<[a-z][^>]*>/i', $data['body']) === 1
            ? \App\Support\HtmlSanitizer::clean($data['body'])
            : trim($data['body']);
        if (mb_strlen(\App\Support\HtmlSanitizer::text($body)) < 2) {
            return redirect()->route('admin.support.show', $ticket)
                ->withErrors(['body' => 'Write a reply first.']);
        }

        $message = $ticket->messages()->create([
            'user_id' => (string) Auth::id(),
            'is_admin' => true,
            'body' => $body,
        ]);
        $ticket->forceFill([
            'status' => SupportTicket::STATUS_ANSWERED,
            'last_reply_at' => now(),
        ])->save();

        // The reply is shown to the customer verbatim (in-app + email) —
        // write it for them, not as an internal note.
        try {
            $to = $ticket->user?->email;
            if ($to) {
                Mail::to($to)->send(new SupportTicketReplied($ticket, $message));
            }
        } catch (\Throwable $e) {
            Log::warning('Support ticket client notification failed: '.$e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticket)->with('status', 'support-replied');
    }

    public function setStatus(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:open,answered,closed']);

        $ticket->forceFill(['status' => $data['status']])->save();

        return redirect()->route('admin.support.show', $ticket)->with('status', 'support-status-saved');
    }
}
