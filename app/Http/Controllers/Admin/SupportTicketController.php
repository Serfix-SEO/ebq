<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SupportTicketReplied;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Website;
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

    /**
     * Start a conversation WITH a client — proactive outreach, a heads-up about
     * their account, following up on something they said elsewhere. Until now
     * tickets could only begin with the customer, so anything we initiated had
     * to happen over email, outside the thread the client can see and reply to.
     */
    public function create(Request $request): View
    {
        return view('admin.support.create', [
            'clients' => $this->clientOptions(),
            'selectedUserId' => (string) $request->query('user', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|string|exists:users,id',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:20000',
        ]);

        // Same sanitize-then-measure-visible-text rule as reply(): the compose
        // box is a rich editor, and "<p><br></p>" is not a message.
        $body = preg_match('/<[a-z][^>]*>/i', $data['body']) === 1
            ? \App\Support\HtmlSanitizer::clean($data['body'])
            : trim($data['body']);
        if (mb_strlen(\App\Support\HtmlSanitizer::text($body)) < 2) {
            return back()->withInput()->withErrors(['body' => 'Write a message first.']);
        }

        $client = User::query()->findOrFail($data['user_id']);

        $ticket = SupportTicket::query()->create([
            'user_id' => $client->id,
            // Attach the website only when there is no ambiguity. Guessing one
            // for a multi-site client would put the wrong context on the thread.
            'website_id' => $this->soleWebsiteId($client),
            'subject' => trim($data['subject']),
            // ANSWERED = the ball is in the client's court. We spoke last, so
            // this must not land in the "customer is waiting on us" queue.
            'status' => SupportTicket::STATUS_ANSWERED,
            'last_reply_at' => now(),
        ]);

        $message = $ticket->messages()->create([
            'user_id' => (string) Auth::id(),
            'is_admin' => true,
            'body' => $body,
        ]);

        try {
            if ($client->email) {
                Mail::to($client->email)->send(new SupportTicketReplied($ticket, $message, isNew: true));
            }
        } catch (\Throwable $e) {
            Log::warning('Support ticket open-with-client notification failed: '.$e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticket)->with('status', 'support-opened');
    }

    /** Every client, newest first, labelled so the picker is unambiguous. */
    private function clientOptions()
    {
        return User::query()
            ->where('is_admin', false)
            // Lead placeholders from the public funnel are not people we can
            // write to — their address is a synthetic @leads.serfix.internal.
            ->where('email', 'not like', '%@leads.serfix.internal')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => (string) $u->id,
                'label' => trim(($u->name ?: 'Unnamed').' — '.$u->email),
            ]);
    }

    private function soleWebsiteId(User $client): ?string
    {
        $ids = Website::query()->where('user_id', $client->id)->pluck('id');

        return $ids->count() === 1 ? (string) $ids->first() : null;
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
