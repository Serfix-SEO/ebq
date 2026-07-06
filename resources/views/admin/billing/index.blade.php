<x-layouts.app>
    <x-admin.plugin-tabs current="billing" />

    @php
        /**
         * @var \Illuminate\Pagination\LengthAwarePaginator $users
         * @var array $summary
         * @var string $q
         * @var string $status
         */
        $relTime = function (?\Illuminate\Support\Carbon $when): string {
            if (! $when) return '—';
            try { return $when->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
    @endphp

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">Subscriptions</h2>
            <p class="text-sm text-slate-500">
                Per-user subscription state. Updated by Stripe webhooks
                (<code>/stripe/webhook</code>) on every billing event. Read-only here —
                use the Stripe Dashboard for refunds, manual upgrades, or
                immediate cancellations.
            </p>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-lg bg-white border border-slate-200 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total users</div>
                <div class="text-2xl font-bold text-slate-900 mt-1">{{ number_format((int) $summary['total']) }}</div>
            </div>
            <div class="rounded-lg bg-orange-50 border border-orange-200 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-orange-700">Paying</div>
                <div class="text-2xl font-bold text-orange-900 mt-1">{{ number_format((int) $summary['paying']) }}</div>
            </div>
            <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">In trial</div>
                <div class="text-2xl font-bold text-amber-900 mt-1">{{ number_format((int) $summary['on_trial']) }}</div>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Free</div>
                <div class="text-2xl font-bold text-slate-900 mt-1">{{ number_format((int) $summary['free']) }}</div>
            </div>
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="search" name="q" value="{{ $q }}"
                   placeholder="Search by name or email"
                   class="text-sm rounded border border-slate-300 px-3 py-1.5" />
            <select name="status" class="text-sm rounded border border-slate-300 px-3 py-1.5">
                <option value="all"    @selected($status === 'all')>All</option>
                <option value="paying" @selected($status === 'paying')>Paying</option>
                <option value="trial"  @selected($status === 'trial')>In trial</option>
                <option value="free"   @selected($status === 'free')>Free</option>
            </select>
            <button class="text-sm rounded bg-slate-800 px-3 py-1.5 font-semibold text-white">Filter</button>
        </form>

        <div class="overflow-auto rounded border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium text-slate-600">User</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Plan</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Sites</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Card</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Trial ends</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Stripe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        @php
                            $isPaying = $user->current_plan_slug !== null && $user->current_plan_slug !== 'free';
                            $planSlug = $user->current_plan_slug ?: 'free';
                        @endphp
                        <tr>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-slate-900">{{ $user->name ?: '—' }}</div>
                                <div class="text-xs text-slate-500">{{ $user->email }}</div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-orange-100 text-orange-800' => $isPaying,
                                    'bg-slate-100 text-slate-700' => ! $isPaying,
                                ])>
                                    {{ $planSlug }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="text-xs text-slate-700">{{ (int) $user->websites_count }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($user->pm_last_four)
                                    <span class="text-xs text-slate-700">{{ ucfirst((string) $user->pm_type) }} ···· {{ $user->pm_last_four }}</span>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($user->trial_ends_at)
                                    <span class="text-xs {{ $user->trial_ends_at->isFuture() ? 'text-amber-700' : 'text-slate-500' }}">
                                        {{ $relTime($user->trial_ends_at) }}
                                    </span>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($user->stripe_id)
                                    <a href="https://dashboard.stripe.com/customers/{{ $user->stripe_id }}"
                                       target="_blank" rel="noopener noreferrer"
                                       class="text-xs font-mono text-orange-700 hover:underline">
                                        {{ \Illuminate\Support\Str::limit($user->stripe_id, 18) }} ↗
                                    </a>
                                @else
                                    <span class="text-xs text-slate-400">no customer</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-12 text-center text-sm text-slate-500">
                                @if ($q !== '' || $status !== 'all')
                                    No users match this filter.
                                @else
                                    No users yet. Once a customer signs up and picks a paid plan, they'll show up here.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            {{ $users->links() }}
        </div>
    </div>
</x-layouts.app>
