<x-layouts.app>
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <style>
            .ticket-body ul { list-style: disc; padding-inline-start: 1.25rem; margin: 0.25rem 0; }
            .ticket-body ol { list-style: decimal; padding-inline-start: 1.25rem; margin: 0.25rem 0; }
            .ticket-body p { margin: 0.25rem 0; }
            .ticket-body a { color: #C44E0E; text-decoration: underline; }
            .ticket-body blockquote { border-inline-start: 3px solid #E2E8F0; padding-inline-start: 0.75rem; margin: 0.25rem 0; }
            .support-editor:empty::before { content: attr(data-placeholder); color: #94A3B8; }
        </style>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('admin.support.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-orange-600 dark:text-slate-400">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    All tickets
                </a>
                <h1 class="mt-1 text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $ticket->subject }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $ticket->user?->name }} · {{ $ticket->user?->email }}
                    @if ($ticket->website) · {{ $ticket->website->domain }} @endif
                    · opened {{ $ticket->created_at->diffForHumans() }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($ticket->status === \App\Models\SupportTicket::STATUS_OPEN)
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">Open</span>
                @elseif ($ticket->status === \App\Models\SupportTicket::STATUS_ANSWERED)
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Answered</span>
                @else
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Closed</span>
                @endif
                <form method="POST" action="{{ route('admin.support.status', $ticket) }}">
                    @csrf
                    @if ($ticket->status === \App\Models\SupportTicket::STATUS_CLOSED)
                        <input type="hidden" name="status" value="open">
                        <button type="submit" class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Re-open</button>
                    @else
                        <input type="hidden" name="status" value="closed">
                        <button type="submit" class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Close</button>
                    @endif
                </form>
            </div>
        </div>

        @if (session('status') === 'support-opened')
            <div class="rounded-2xl border border-success/25 bg-white p-4 text-sm font-semibold text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100">Ticket opened — the client was emailed and can reply from their Support page.</div>
        @endif
        @if (session('status') === 'support-replied')
            <div class="rounded-2xl border border-success/25 bg-white p-4 text-sm font-semibold text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100">Reply sent — the customer was notified by email.</div>
        @endif

        @if ($bugReport !== null)
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-300">
                <span class="font-bold">From a bug report</span>
                <span class="truncate">page: <a href="{{ $bugReport->url }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline dark:text-orange-400">{{ \Illuminate\Support\Str::limit($bugReport->url, 60) }}</a></span>
                @if ($bugReport->viewport) <span>viewport: {{ $bugReport->viewport }}</span> @endif
                @if ($bugReport->screenshot_path)
                    <a href="{{ route('admin.bug-reports.screenshot', $bugReport) }}" target="_blank" class="font-semibold text-orange-600 hover:underline dark:text-orange-400">View screenshot</a>
                @endif
            </div>
        @endif

        <div class="space-y-3">
            @foreach ($messages as $msg)
                <div class="flex {{ $msg->is_admin ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] rounded-2xl border px-4 py-3 shadow-sm {{ $msg->is_admin
                        ? 'border-orange-200 bg-orange-50/60 dark:border-orange-900 dark:bg-orange-950/40'
                        : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900' }}">
                        <div class="flex items-center gap-2 text-xs font-bold {{ $msg->is_admin ? 'text-orange-700 dark:text-orange-300' : 'text-slate-700 dark:text-slate-300' }}">
                            {{ $msg->is_admin ? ($msg->user?->name ?? 'Team').' (team)' : ($msg->user?->name ?? 'Client') }}
                            <span class="font-normal text-slate-400">{{ $msg->created_at->format('M j, H:i') }}</span>
                        </div>
                        <div class="ticket-body mt-1.5 text-sm leading-relaxed text-slate-800 dark:text-slate-200">{!! $msg->bodyHtml() !!}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('admin.support.reply', $ticket) }}"
            class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @csrf
            <p class="text-xs text-slate-500 dark:text-slate-400">The reply is shown to the customer in-app and emailed verbatim — write it for them.</p>
            <div class="mt-2">
                <x-support.html-editor name="body" placeholder="Write a reply…" />
            </div>
            @error('body') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            <div class="mt-3 flex justify-end">
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    Send reply
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
