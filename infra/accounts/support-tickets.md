# Support tickets (2026-08-12)

Threaded client ↔ team conversations. **Bug reports ARE support tickets** —
owner decision 2026-08-12: every bug report creates a ticket thread so the
client can follow up and see replies (the modal used to be a dead end).

## Data

`support_tickets` (central connection; migration `2026_08_12_180000`):
ULID pk, `user_id` FK cascade, `website_id` soft ref (triage context only —
tickets are per-USER, not per-website), `subject` (200), `status`
(`open|answered|closed`), `last_reply_at`.
`support_ticket_messages`: `ticket_id` FK cascade, `user_id` FK (author),
`is_admin` (snapshot at write time), `body`.

**Status = whose turn it is**: `open` → customer waiting on us (the admin
work queue), `answered` → we replied, waiting on customer, `closed` → done.
A client reply ALWAYS flips to `open` — including re-opening a closed ticket
(`TicketThread::send`, `app/Livewire/Support/TicketThread.php`).

## Client side

- Nav "Support" in the ungrouped tail of `$navGroups`
  (`resources/views/components/layouts/app.blade.php`, after Billing) —
  `feature => null`, survives the SEO kill-switch.
- Routes (`routes/web.php`, auth+verified+onboarded group): `support.index`
  (`Route::view`) + `support.show/{ticket}` (closure view). **`support.*` is
  in `EnsureOnboarded`'s allowlist** — a user with zero websites must still
  reach help (`app/Http/Middleware/EnsureOnboarded.php`).
- Livewire `app/Livewire/Support/Tickets.php` (list + create; RateLimiter 5
  new tickets/hour/user) and `TicketThread.php` (ownership re-checked on
  every action via `where('user_id', Auth::id())`, never trusted from props).

## Admin side

- `/admin/support` — `app/Http/Controllers/Admin/SupportTicketController.php`
  (index w/ status filter tiles + "N awaiting reply" pill, show w/ thread,
  POST reply, POST status). Blades `resources/views/admin/support/`.
  Admin copy English-only per convention.
- Admin reply → message `is_admin=true` + status `answered` +
  `SupportTicketReplied` mail to the customer (**shown verbatim in-app and in
  email — write it for the customer**).
- **Admin can OPEN a ticket with any client** (2026-08-18): `/admin/support/new`
  → `create()` / `store()`, "New ticket" button on the index and a **Message**
  button on `/admin/clients/{id}` (pre-selects that client via `?user=`).
  Before this a thread could only begin with the customer, so anything we
  initiated happened over plain email — outside the thread they can see, reply
  to and find again.
  - Opens as **`answered`**, not `open`: we spoke last, so it must not land in
    the "customer is waiting on us" work queue.
  - `website_id` is attached **only when the client has exactly one site** —
    guessing for a multi-site client puts the wrong context on the thread.
  - Client picker excludes `%@leads.serfix.internal` (funnel lead placeholders
    have no real address). Plain `<select>`, fine at current scale; revisit if
    the client list grows past a few hundred.
  - Same sanitize-then-measure-visible-text rule as `reply()`, so
    `<p><br></p>` is rejected rather than sending an empty message.

## Mail

- `App\Mail\SupportTicketActivity` → all `is_admin` users on client
  create/reply (English, inline-HTML heredoc style like BugReportSubmitted).
- `App\Mail\SupportTicketReplied` → customer on admin reply (localized
  envelope, links to `/support/{id}`). Carries an **`isNew`** flag: when WE
  opened the thread the subject/intro become "A message from the Serfix team"
  instead of "We've replied to your ticket", which would be a lie.
- All sends synchronous + try/catch — mail failure never breaks the action.

## Bug-report bridge

- `SupportTicket::createFromBugReport()` (`app/Models/SupportTicket.php`) —
  ticket + first message from a `BugReport`, timestamps mirrored, links via
  `bug_reports.support_ticket_id` (migration `2026_08_12_190000`, soft ref).
- `BugReportModal::submit` creates the ticket alongside the report (no extra
  admin mail — BugReportSubmitted already covers it).
- `Admin\BugReportController::resolve` mirrors the resolution note into the
  ticket thread (`answered`); un-resolve re-opens it.
- Admin ticket view shows the bug-report context strip (page URL, viewport,
  screenshot link) when the ticket came from a report.
- One-time backfill `ebq:backfill-bug-report-tickets` (idempotent — skips
  reports already linked; resolved reports → closed ticket with the
  resolution note as the team reply). Run on prod 2026-08-12.

## Gotchas

- New routes → **`php artisan route:cache` on deploy** (prod route-cache
  landmine) — phpunit reads the same cache. This bit again while adding
  `/support/new`: the tests 404'd until `route:clear`.
- ⚠️ `/admin/support/new` MUST stay registered **before** `/admin/support/{ticket}`
  or the literal is captured as a ticket id. A test pins it.
- Tests: `tests/Feature/Support/` (client, admin, bridge).
