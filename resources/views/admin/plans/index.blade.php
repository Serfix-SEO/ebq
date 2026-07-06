<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Database\Eloquent\Collection $plans
         */
        $usd = fn (int $cents) => '$'.number_format($cents);
    @endphp

    <div class="space-y-6">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Subscription plans</h1>
                <p class="text-sm text-slate-500 mt-1">
                    Drives the marketing /pricing page, the public
                    <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">/api/v1/plans</code>
                    endpoint (read by the WordPress plugin's setup wizard), and the
                    <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">/billing/checkout</code>
                    flow that hands Stripe price IDs to Cashier.
                </p>
            </div>
            <a href="{{ route('admin.plans.create') }}"
               class="text-sm font-medium px-3 py-1.5 rounded-md bg-orange-600 text-white hover:bg-orange-500 whitespace-nowrap">
                + New plan
            </a>
        </div>

        @if (session('status'))
            <div class="rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        <div class="rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-800">
            <strong class="font-semibold">Stripe price IDs:</strong>
            Serfix only sells <em>yearly</em> subscriptions. Create products + yearly prices in your Stripe dashboard, then paste each
            <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs">price_…</code>
            ID into its plan row below. Plans without a yearly Stripe price ID show as
            "Coming soon" on the pricing page and can't accept checkout sessions. The monthly price column is display-only ("$X/mo, billed yearly").
        </div>

        <div class="overflow-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium text-slate-600">Order</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Slug</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Plan</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Monthly</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Yearly</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Trial</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Stripe</th>
                        <th class="px-3 py-2 font-medium text-slate-600">Active</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($plans as $plan)
                        <tr class="{{ $plan->is_active ? '' : 'opacity-60' }}">
                            <td class="px-3 py-2.5 text-xs text-slate-500">{{ $plan->display_order }}</td>
                            <td class="px-3 py-2.5">
                                <code class="text-xs font-mono text-slate-700">{{ $plan->slug }}</code>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-slate-900">
                                    {{ $plan->name }}
                                    @if ($plan->is_highlighted)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-orange-800 tracking-wide">Featured</span>
                                    @endif
                                </div>
                                @if ($plan->tagline)
                                    <div class="text-xs text-slate-500 mt-0.5">{{ $plan->tagline }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-sm font-mono text-slate-700">
                                {{ $usd((int) $plan->price_monthly_usd) }}
                            </td>
                            <td class="px-3 py-2.5 text-sm font-mono text-slate-700">
                                {{ $plan->price_yearly_usd ? $usd((int) $plan->price_yearly_usd) : '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-xs text-slate-700">
                                {{ $plan->trial_days ? $plan->trial_days.' days' : '—' }}
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($plan->stripe_price_id_yearly)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                                        ✓ {{ \Illuminate\Support\Str::limit($plan->stripe_price_id_yearly, 18) }}
                                    </span>
                                @elseif ((int) $plan->price_yearly_usd === 0)
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        Free tier
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                                        Missing yearly ID
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($plan->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Inactive</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <a href="{{ route('admin.plans.edit', $plan) }}"
                                   class="text-xs font-medium text-orange-700 hover:underline">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-12 text-center text-sm text-slate-500">
                                No plans yet. Run
                                <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">php artisan db:seed --class=PlanSeeder</code>
                                to seed the default 4-tier set, or click "+ New plan" above.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
