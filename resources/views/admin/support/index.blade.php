<x-layouts.app>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                Support tickets
                @if (($counts['open'] ?? 0) > 0)
                    <span class="ms-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ $counts['open'] }} awaiting reply</span>
                @endif
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Open = the customer is waiting on us. Replying flips a ticket to Answered and emails them.</p>
            </div>
            <a href="{{ route('admin.support.create') }}"
                class="inline-flex flex-none items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                New ticket
            </a>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ([
                '' => 'All ('.array_sum($counts->all()).')',
                \App\Models\SupportTicket::STATUS_OPEN => 'Open ('.($counts['open'] ?? 0).')',
                \App\Models\SupportTicket::STATUS_ANSWERED => 'Answered ('.($counts['answered'] ?? 0).')',
                \App\Models\SupportTicket::STATUS_CLOSED => 'Closed ('.($counts['closed'] ?? 0).')',
            ] as $value => $label)
                <a href="{{ route('admin.support.index', array_filter(['status' => $value])) }}"
                    class="rounded-xl border px-4 py-2 text-sm font-semibold {{ $status === $value ? 'border-orange-500 bg-orange-50 text-orange-700 ring-2 ring-orange-500 dark:bg-orange-950 dark:text-orange-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead>
                    <tr class="text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th class="px-4 py-3 text-start">Updated</th>
                        <th class="px-4 py-3 text-start">Client</th>
                        <th class="px-4 py-3 text-start">Website</th>
                        <th class="px-4 py-3 text-start">Subject</th>
                        <th class="px-4 py-3 text-start">Msgs</th>
                        <th class="px-4 py-3 text-start">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($tickets as $ticket)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500 dark:text-slate-400">{{ ($ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $ticket->user?->name ?? '—' }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $ticket->user?->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $ticket->website?->domain ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.support.show', $ticket) }}" class="font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ \Illuminate\Support\Str::limit($ticket->subject, 70) }}</a>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $ticket->messages_count }}</td>
                            <td class="px-4 py-3">
                                @if ($ticket->status === \App\Models\SupportTicket::STATUS_OPEN)
                                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">Open</span>
                                @elseif ($ticket->status === \App\Models\SupportTicket::STATUS_ANSWERED)
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Answered</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Closed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">No tickets yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $tickets->links() }}</div>
    </div>
</x-layouts.app>
