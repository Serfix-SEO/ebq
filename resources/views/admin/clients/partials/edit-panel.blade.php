{{-- Inline client edit panel: profile fields, admin/disabled flags, comped
     dashboard plan, comped Content Autopilot slots, plus the separate
     site-crawl form. Shared by the desktop table row and the mobile card so
     the two layouts can never drift apart.

     Expects: $client, $plans --}}
<form method="POST" action="{{ route('admin.clients.update', $client) }}" class="space-y-3">
    @csrf
    @method('PUT')
    {{-- Set on the client detail page so saving returns there, not to the list. --}}
    @isset($returnTo) <input type="hidden" name="return_to" value="{{ $returnTo }}" /> @endisset
    <div class="grid gap-3 md:grid-cols-3">
        <label class="flex flex-col gap-1 text-xs text-slate-600">
            <span class="font-medium">Name</span>
            <input type="text" name="name" value="{{ $client->name }}" required
                   class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
        </label>
        <label class="flex flex-col gap-1 text-xs text-slate-600 md:col-span-2">
            <span class="font-medium">Email</span>
            <input type="email" name="email" value="{{ $client->email }}" required
                   class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
        </label>
    </div>

    <div class="flex flex-wrap items-center gap-4 rounded-md border border-slate-200 bg-white px-3 py-2">
        <label class="flex items-center gap-2 text-xs text-slate-700">
            <input type="checkbox" name="is_admin" value="1" @checked($client->is_admin)
                   class="rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
            <span class="font-medium">Admin</span>
            <span class="text-slate-400">Grants access to /admin pages.</span>
        </label>
        <span class="text-slate-200">|</span>
        <label class="flex items-center gap-2 text-xs text-slate-700">
            <input type="checkbox" name="is_disabled" value="1" @checked($client->is_disabled)
                   class="rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
            <span class="font-medium">Disabled</span>
            <span class="text-slate-400">Blocks login until reactivated.</span>
        </label>
    </div>

    {{-- Force-apply plan (comp) — sets current_plan_slug with no Stripe
         charge. Takes effect on the client's next request.
         Bug fixed 2026-07-03: this defaulted to 'free', which no plan row
         has matched since the 5-tier rework renamed it to 'legacy_free' —
         so @selected() never matched any <option> and the browser silently
         pre-selected whatever was first in $plans (legacy_free), NOT the
         client's real plan. An admin picking "Trial" off that wrong default
         was submitting a silent no-op (current_plan_slug already null ==
         trial via User::TIER_FREE alias) with zero visible effect and no
         admin.client_plan_forced log. --}}
    @php $currentPlanSlug = $client->current_plan_slug ?: 'trial'; @endphp
    <div class="rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2.5">
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs text-slate-700">
                <span class="font-medium">Force-apply plan <span class="font-normal text-amber-700">(comp — no payment)</span></span>
                <select name="plan_slug"
                        class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm sm:w-auto sm:min-w-[200px] focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->slug }}" @selected($currentPlanSlug === $plan->slug)>
                            {{ $plan->name }} ({{ $plan->slug }})@if (! $plan->is_active) — inactive @endif
                        </option>
                    @endforeach
                </select>
            </label>
            <p class="w-full text-[11px] leading-relaxed text-amber-800 sm:flex-1">
                Grants this plan's website limit and plugin features for free.
                Takes effect on the client's next request. <strong>Note:</strong> an
                active paid Stripe subscription still takes precedence over a comp.
            </p>
        </div>
        @error('plan_slug') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    {{-- Comp FREE Content Autopilot website slots (separate product from the
         dashboard plan above). Additive to any real content subscription/trial.
         0 = none; blank date = permanent. --}}
    <div class="rounded-md border border-sky-200 bg-sky-50/60 px-3 py-2.5">
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs text-slate-700">
                <span class="font-medium">Content Autopilot free sites <span class="font-normal text-sky-700">(comp)</span></span>
                <input type="number" name="content_comp_sites" min="0" max="1000"
                       value="{{ old('content_comp_sites', (int) ($client->content_comp_sites ?? 0)) }}"
                       class="w-28 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-sky-500 focus:ring-1 focus:ring-sky-500" />
            </label>
            <label class="flex flex-col gap-1 text-xs text-slate-700">
                <span class="font-medium">Free until <span class="font-normal text-slate-400">(blank = permanent)</span></span>
                <input type="date" name="content_comp_until"
                       value="{{ old('content_comp_until', $client->content_comp_until?->toDateString()) }}"
                       class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-sky-500 focus:ring-1 focus:ring-sky-500" />
            </label>
            <p class="w-full text-[11px] leading-relaxed text-sky-800 sm:flex-1">
                Grants N websites of Content Autopilot for free (no Stripe). Adds to any
                real content subscription. Reducing the count un-covers the newest sites
                (non-destructive). Takes effect on the client's next request.
            </p>
        </div>
        @error('content_comp_sites') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        @error('content_comp_until') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('admin.usage.index', ['user_id' => $client->id]) }}"
           class="text-xs font-semibold text-orange-600 hover:underline">
            View this client's API usage →
        </a>
        <div class="flex justify-end gap-2">
            <a href="{{ ($returnTo ?? null) === 'show'
                        ? route('admin.clients.show', $client)
                        : route('admin.clients.index', array_merge(request()->query(), ['edit' => 0])) }}"
               class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
            <button class="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700">Save changes</button>
        </div>
    </div>
</form>

{{-- Admin site crawl. Separate form (can't nest in the edit form).
     Picks the website when the client has more than one. --}}
<form method="POST" action="{{ route('admin.clients.crawl', $client) }}"
      class="mt-3 flex flex-wrap items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2">
    @csrf
    <span class="text-xs font-medium text-slate-700">Site crawl</span>
    @php $clientWebsites = $client->websites; @endphp
    @if ($clientWebsites->isEmpty())
        <span class="text-xs text-slate-400">No websites to crawl.</span>
    @elseif ($clientWebsites->count() === 1)
        <input type="hidden" name="website_id" value="{{ $clientWebsites->first()->id }}" />
        <span class="text-xs text-slate-500">{{ $clientWebsites->first()->domain }}</span>
        <button class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Recrawl site</button>
    @else
        <select name="website_id" required
                class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:w-auto sm:min-w-[200px] focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
            <option value="">Select website…</option>
            @foreach ($clientWebsites as $w)
                <option value="{{ $w->id }}">{{ $w->domain }}</option>
            @endforeach
        </select>
        <button class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Recrawl selected</button>
    @endif
    <span class="text-[11px] text-slate-400">Re-fetches all pages and refreshes Site Health + Link Structure.</span>
</form>
