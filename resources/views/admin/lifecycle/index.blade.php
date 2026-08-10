<x-layouts.app>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Lifecycle emails</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Segment-matched onboarding emails (from Fuaad, reply-driven) — who's stuck where, what we sent, and what moved.</p>
        </div>

        @if (session('status') === 'lifecycle-settings-saved')
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Settings saved.</div>
        @elseif (str_starts_with((string) session('status'), 'lifecycle-test-sent:'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Test email sent to {{ \Illuminate\Support\Str::after(session('status'), ':') }}.</div>
        @elseif (session('status') === 'lifecycle-test-failed')
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-500/10 dark:text-rose-300">Test send failed: {{ session('lifecycle_error') }}</div>
        @endif

        {{-- Segment tiles: live eligible count + funnel numbers; click filters the log --}}
        @php
            $tileMeta = [
                2 => ['label' => 'No website yet', 'desc' => 'Registered, never added a site', 'cls' => 'border-rose-200 text-rose-700 dark:border-rose-800 dark:text-rose-300'],
                3 => ['label' => 'Strategy unfinished', 'desc' => 'Site added, wizard not completed', 'cls' => 'border-orange-200 text-orange-700 dark:border-orange-800 dark:text-orange-300'],
                4 => ['label' => 'Not connected', 'desc' => 'Strategy live, no publish connection', 'cls' => 'border-amber-200 text-amber-700 dark:border-amber-800 dark:text-amber-300'],
                1 => ['label' => 'Articles flowing', 'desc' => 'Producing content — feedback ask', 'cls' => 'border-emerald-200 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($tileMeta as $s => $t)
                <a href="{{ route('admin.lifecycle.index', ['segment' => $s]) }}"
                    @class([
                        'rounded-xl border bg-white p-4 transition hover:shadow-sm dark:bg-slate-900',
                        $t['cls'],
                        'ring-2 ring-orange-500' => $segment === (string) $s,
                    ])>
                    <div class="text-2xl font-extrabold">{{ number_format($eligible[$s] ?? 0) }}</div>
                    <div class="text-xs font-semibold">{{ $t['label'] }}</div>
                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $t['desc'] }}</div>
                    <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        {{ number_format($stats[$s]['sent']) }} sent
                        · {{ number_format($stats[$s]['followups']) }} follow-up
                        · {{ number_format($stats[$s]['converted']) }} moved on{{ $stats[$s]['conversion'] !== null ? ' ('.$stats[$s]['conversion'].'%)' : '' }}
                        @if ($stats[$s]['failed'] > 0)
                            · <span class="font-semibold text-rose-600 dark:text-rose-400">{{ number_format($stats[$s]['failed']) }} failed</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
        <p class="text-xs text-slate-400 dark:text-slate-500">Big number = users currently in the segment who could be emailed (cached ~10 min). "Moved on" = user left the segment after we emailed them.</p>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Settings --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Sending controls</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Emails go out automatically once a day (10:05). Replies land at <span class="font-semibold">{{ $settings['reply_to'] }}</span>.</p>
                <form method="POST" action="{{ route('admin.lifecycle.settings') }}" class="mt-4 space-y-3 text-sm">
                    @csrf
                    @method('PUT')
                    <label class="flex items-center gap-2 font-semibold text-slate-700 dark:text-slate-200">
                        <input type="checkbox" name="enabled" value="1" @checked($settings['enabled']) class="rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-600">
                        Master switch — send lifecycle emails
                    </label>
                    <div class="grid grid-cols-2 gap-2 pl-6">
                        @foreach ([2 => 'No website yet', 3 => 'Strategy unfinished', 4 => 'Not connected', 1 => 'Articles flowing'] as $s => $label)
                            <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="segment_{{ $s }}" value="1" @checked($settings['segments'][$s]) class="rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-end gap-4 pt-1">
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Daily send cap</span>
                            <input type="number" name="daily_cap" value="{{ $settings['daily_cap'] }}" min="0" max="5000" class="mt-1 block w-28 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Min account age (days)</span>
                            <input type="number" name="min_account_age_days" value="{{ $settings['min_account_age_days'] }}" min="0" max="60" class="mt-1 block w-28 rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        </label>
                        <button type="submit" class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500">Save</button>
                    </div>
                    @error('daily_cap')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('min_account_age_days')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </form>
            </div>

            {{-- Test send --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Send a test email</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Preview any of the 8 emails at any address. Never logged, never affects what a real user receives.</p>
                <form method="POST" action="{{ route('admin.lifecycle.test-send') }}" class="mt-4 space-y-3 text-sm">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Send to</span>
                        <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    </label>
                    <div class="flex flex-wrap items-end gap-4">
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Segment</span>
                            <select name="segment" class="mt-1 block rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <option value="2">2 — No website yet</option>
                                <option value="3">3 — Strategy unfinished</option>
                                <option value="4">4 — Not connected</option>
                                <option value="1">1 — Articles flowing</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Stage</span>
                            <select name="stage" class="mt-1 block rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <option value="initial">Email 1</option>
                                <option value="followup">Follow-up</option>
                            </select>
                        </label>
                        <button type="submit" class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500">Send test</button>
                    </div>
                    @error('email')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </form>
            </div>
        </div>

        {{-- Send log --}}
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Send log</h2>
                <form method="GET" action="{{ route('admin.lifecycle.index') }}" class="flex flex-wrap items-center gap-2 text-xs">
                    <select name="segment" onchange="this.form.submit()" class="rounded-lg border-slate-300 text-xs focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">All segments</option>
                        @foreach ([2 => 'No website yet', 3 => 'Strategy unfinished', 4 => 'Not connected', 1 => 'Articles flowing'] as $s => $label)
                            <option value="{{ $s }}" @selected($segment === (string) $s)>{{ $s }} — {{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="stage" onchange="this.form.submit()" class="rounded-lg border-slate-300 text-xs focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">All stages</option>
                        <option value="initial" @selected($stage === 'initial')>Email 1</option>
                        <option value="followup" @selected($stage === 'followup')>Follow-up</option>
                    </select>
                    <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 text-xs focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">All statuses</option>
                        <option value="sent" @selected($status === 'sent')>Sent</option>
                        <option value="failed" @selected($status === 'failed')>Failed</option>
                    </select>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Client</th>
                            <th class="px-4 py-3">Segment</th>
                            <th class="px-4 py-3">Stage</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Moved on</th>
                            <th class="px-4 py-3">Subject</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($rows as $row)
                            <tr class="align-top text-slate-700 dark:text-slate-300">
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ $row->created_at?->diffForHumans() }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900 dark:text-slate-100">{{ $row->user?->name ?: '—' }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ $row->to_email }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs">{{ $row->segment }} — {{ \App\Models\LifecycleEmailSend::SEGMENTS[$row->segment] ?? '?' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs">{{ $row->stage === 'initial' ? 'Email 1' : 'Follow-up' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $row->status === 'sent' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' }}">{{ $row->status === 'sent' ? 'Sent' : 'Failed' }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs">
                                    @if ($row->converted_at)
                                        <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ $row->converted_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="max-w-sm px-4 py-3 text-xs text-slate-600 dark:text-slate-400">
                                    {{ $row->subject }}
                                    @if ($row->status === 'failed' && ($row->meta['error'] ?? null))
                                        <div class="mt-1 text-rose-600 dark:text-rose-400">{{ \Illuminate\Support\Str::limit($row->meta['error'], 120) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-400">Nothing sent yet — the daily run goes out at 10:05, or use the test form above.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $rows->links() }}</div>
    </div>
</x-layouts.app>
