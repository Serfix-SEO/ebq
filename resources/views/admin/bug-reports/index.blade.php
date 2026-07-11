<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Bug Reports</h1>
                <p class="text-sm text-slate-500">In-app reports from the "Report a bug" button (with optional screenshots).</p>
            </div>
            <span class="rounded-lg bg-amber-100 px-3 py-1.5 text-sm font-semibold text-amber-700">{{ number_format($newCount) }} new</span>
        </div>

        @if (session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->has('resolution_note'))
            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">A resolution note is required to resolve a report.</div>
        @endif

        <div class="flex gap-2 text-sm">
            @foreach (['' => 'All', 'new' => 'New', 'resolved' => 'Resolved'] as $key => $label)
                <a href="{{ route('admin.bug-reports.index', array_filter(['status' => $key])) }}"
                    class="rounded-lg px-3 py-1.5 font-semibold {{ $status === $key ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="overflow-auto rounded border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-3 py-2">Reported</th>
                        <th class="px-3 py-2">User</th>
                        <th class="px-3 py-2">Page</th>
                        <th class="px-3 py-2">Description</th>
                        <th class="px-3 py-2">Env</th>
                        <th class="px-3 py-2">Screenshot</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reports as $report)
                        <tr class="border-t border-slate-100 align-top">
                            <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ $report->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-slate-800">{{ $report->user?->name ?? '—' }}</div>
                                <div class="text-xs text-slate-500">{{ $report->user?->email }}</div>
                            </td>
                            <td class="max-w-[220px] px-3 py-2">
                                <a href="{{ $report->url }}" target="_blank" rel="noopener" class="block truncate text-orange-600 hover:underline" title="{{ $report->url }}">{{ $report->url }}</a>
                            </td>
                            <td class="max-w-md px-3 py-2 text-slate-700">
                                <details>
                                    <summary class="cursor-pointer">{{ \Illuminate\Support\Str::limit($report->description, 90) }}</summary>
                                    <div class="mt-1 whitespace-pre-wrap text-xs text-slate-600">{{ $report->description }}</div>
                                </details>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500" title="{{ $report->user_agent }}">{{ $report->viewport ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($report->screenshot_path)
                                    <a href="{{ route('admin.bug-reports.screenshot', $report) }}" target="_blank" rel="noopener">
                                        <img src="{{ route('admin.bug-reports.screenshot', $report) }}" alt="Screenshot" class="h-12 rounded border border-slate-200 object-cover" loading="lazy" />
                                    </a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="min-w-[240px] px-3 py-2">
                                @if ($report->status === \App\Models\BugReport::STATUS_RESOLVED)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Resolved {{ $report->resolved_at?->format('M j') }}</span>
                                    @if ($report->resolution_note)
                                        <details class="mt-1 text-xs text-slate-600">
                                            <summary class="cursor-pointer text-slate-500">Fix note</summary>
                                            <div class="mt-1 whitespace-pre-wrap">{{ $report->resolution_note }}</div>
                                        </details>
                                    @endif
                                    <form method="POST" action="{{ route('admin.bug-reports.resolve', $report) }}" class="mt-1">
                                        @csrf
                                        <button class="text-xs text-slate-500 underline hover:text-slate-700">Reopen</button>
                                    </form>
                                @else
                                    <details>
                                        <summary class="inline-flex cursor-pointer items-center rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Resolve…</summary>
                                        <form method="POST" action="{{ route('admin.bug-reports.resolve', $report) }}" class="mt-2 space-y-1.5">
                                            @csrf
                                            <textarea name="resolution_note" rows="3" required maxlength="5000"
                                                placeholder="What was fixed — written for the customer, this text is emailed to them verbatim."
                                                class="w-full rounded border border-slate-300 px-2 py-1.5 text-xs">{{ old('resolution_note') }}</textarea>
                                            @error('resolution_note') <p class="text-[11px] text-red-600">{{ $message }}</p> @enderror
                                            <button class="rounded bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Mark resolved &amp; notify reporter</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-slate-400">No bug reports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $reports->links() }}</div>
    </div>
</x-layouts.app>
