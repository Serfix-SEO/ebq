<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Pagination\LengthAwarePaginator $clients
         * @var array $summary
         * @var array $rates
         * @var string $editId
         * @var bool $showCreate
         */
        $fmtN = fn ($n) => number_format((int) $n);
        $fmtMoney = fn (float $usd) => '$' . number_format($usd, $usd >= 100 ? 0 : ($usd >= 1 ? 2 : 4));
        $initialsFor = function (string $name, string $email): string {
            $n = trim($name);
            if ($n !== '') {
                $parts = preg_split('/\s+/', $n) ?: [];
                $first = mb_substr($parts[0] ?? '', 0, 1);
                $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
                return mb_strtoupper($first . $last);
            }
            return mb_strtoupper(mb_substr($email, 0, 2));
        };
        $relTime = function (?string $when): string {
            if (! $when) return '—';
            try { return \Illuminate\Support\Carbon::parse($when)->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
        $avatarBg = function (string $id): string {
            $palette = ['bg-orange-100 text-orange-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-700',
                        'bg-rose-100 text-rose-700', 'bg-sky-100 text-sky-700', 'bg-orange-100 text-orange-700',
                        'bg-teal-100 text-teal-700', 'bg-fuchsia-100 text-fuchsia-700'];
            return $palette[crc32($id) % count($palette)];
        };
        $statusOptions = [
            'all'      => ['label' => 'All',      'count' => $summary['total']],
            'active'   => ['label' => 'Active',   'count' => $summary['total'] - $summary['disabled']],
            'admins'   => ['label' => 'Admins',   'count' => $summary['admins']],
            'disabled' => ['label' => 'Disabled', 'count' => $summary['disabled']],
        ];
    @endphp

    <div class="space-y-5">
        {{-- Page header --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Clients</h1>
                <p class="text-sm text-slate-500">Accounts on the platform — admin flags, status, monthly API spend.</p>
            </div>
            <a href="{{ route('admin.clients.index', array_merge(request()->query(), ['new' => 1])) }}#new-client"
               class="inline-flex items-center gap-1.5 rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-orange-700">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New client
            </a>
        </div>

        {{-- Flash --}}
        @if (session('status'))
            <div class="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('status') }}
            </div>
        @endif

        {{-- Summary stats --}}
        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['label' => 'Total clients',  'value' => $summary['total'],    'tone' => 'slate'],
                ['label' => 'Admins',         'value' => $summary['admins'],   'tone' => 'orange'],
                ['label' => 'Disabled',       'value' => $summary['disabled'], 'tone' => 'rose'],
                ['label' => 'New this week',  'value' => $summary['new_7d'],   'tone' => 'emerald'],
                ['label' => 'Trial → paid',   'value' => $summary['converted_paid'],  'tone' => 'emerald'],
                ['label' => 'Trial + card added', 'value' => $summary['trial_with_card'], 'tone' => 'sky'],
            ] as $s)
                <div class="rounded-md border border-slate-200 bg-white px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $s['label'] }}</p>
                    <p @class([
                        'mt-0.5 text-xl font-bold tabular-nums',
                        'text-slate-800' => $s['tone'] === 'slate',
                        'text-orange-700' => $s['tone'] === 'orange',
                        'text-rose-700' => $s['tone'] === 'rose' && $s['value'] > 0,
                        'text-slate-400' => $s['tone'] === 'rose' && $s['value'] === 0,
                        'text-emerald-700' => $s['tone'] === 'emerald',
                        'text-sky-700' => $s['tone'] === 'sky',
                    ])>{{ $fmtN($s['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Create-client panel (collapsed by default; toggled via ?new=1 link) --}}
        <details id="new-client" class="rounded-md border border-slate-200 bg-white" @if($showCreate) open @endif>
            <summary class="flex cursor-pointer select-none items-center justify-between px-4 py-3 text-sm font-semibold text-slate-800">
                <span class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    New client
                </span>
                <span class="text-[10px] uppercase tracking-wider text-slate-400 group-open:hidden">Click to expand</span>
            </summary>
            <form method="POST" action="{{ route('admin.clients.store') }}" class="border-t border-slate-100 p-4">
                @csrf
                <div class="grid gap-3 md:grid-cols-4">
                    <label class="flex flex-col gap-1 text-xs text-slate-600 md:col-span-1">
                        <span class="font-medium">Full name</span>
                        <input type="text" name="name" value="{{ old('name') }}" required autocomplete="off"
                               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-slate-600 md:col-span-2">
                        <span class="font-medium">Email</span>
                        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="off"
                               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-slate-600 md:col-span-1">
                        <span class="font-medium">Temporary password</span>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password"
                               class="rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
                    </label>
                </div>
                @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                <div class="mt-3 flex items-center justify-between">
                    <label class="flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="is_admin" value="1" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
                        <span class="font-medium">Make admin</span>
                    </label>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.clients.index') }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                        <button class="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700">Create client</button>
                    </div>
                </div>
            </form>
        </details>

        {{-- Filters bar --}}
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <div class="relative w-full flex-1 sm:w-auto sm:min-w-[260px]">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input type="text" name="q" value="{{ $q }}" placeholder="Search by name, email or website domain…" autocomplete="off"
                       class="w-full rounded-md border border-slate-300 pl-8 pr-3 py-1.5 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" />
            </div>
            <input type="hidden" name="status" value="{{ $status }}" id="status-input" />
            <select name="sort" onchange="this.form.submit()"
                    class="rounded-md border border-slate-300 px-2 py-1.5 text-xs font-medium text-slate-700">
                <option value="recent" @selected($sort === 'recent')>Newest first</option>
                <option value="name"   @selected($sort === 'name')>Name A→Z</option>
                <option value="email"  @selected($sort === 'email')>Email A→Z</option>
                <option value="spend"  @selected($sort === 'spend')>Spend (MTD)</option>
            </select>
            <button class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Search</button>

            {{-- Status pills (single-select tabs). Scroll sideways on narrow
                 phones rather than wrapping into a ragged second row. --}}
            <div class="-mx-0.5 flex w-full gap-1 overflow-x-auto rounded-md border border-slate-200 bg-slate-50 p-0.5 sm:ml-auto sm:w-auto">
                @foreach ($statusOptions as $key => $opt)
                    <button type="submit" name="status" value="{{ $key }}"
                            @class([
                                'flex-shrink-0 rounded px-2.5 py-1 text-[11px] font-semibold transition',
                                'bg-white text-orange-700 shadow-sm' => $status === $key,
                                'text-slate-600 hover:text-slate-900' => $status !== $key,
                            ])>
                        {{ $opt['label'] }}
                        <span @class(['ml-1 tabular-nums', 'text-slate-400' => $status !== $key, 'text-orange-400' => $status === $key])>{{ $fmtN($opt['count']) }}</span>
                    </button>
                @endforeach
            </div>
        </form>

        {{-- Clients list.
             User ids are ULIDs, so every id crossing into an Alpine expression
             must be a quoted JS string — an (int) cast collapsed them all to 0
             and `isSelected(01m008…)` was a JS syntax error, which is why bulk
             select silently did nothing. --}}
        @php
            $selfId = (string) (auth()->id() ?? '');
            $selectableIds = $clients->getCollection()
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->filter(fn (string $id) => $id !== $selfId)
                ->values()
                ->all();
        @endphp
        <div
            x-data="{
                selected: [],
                allIds: @js($selectableIds),
                toggleAll(checked) { this.selected = checked ? [...this.allIds] : []; },
                togglePage(checked) { this.toggleAll(checked); },
                isSelected(id) { return this.selected.includes(id); },
                toggle(id) {
                    const i = this.selected.indexOf(id);
                    if (i === -1) this.selected.push(id); else this.selected.splice(i, 1);
                },
                clear() { this.selected = []; },
                get allSelected() { return this.allIds.length > 0 && this.selected.length === this.allIds.length; },
                get someSelected() { return this.selected.length > 0 && this.selected.length < this.allIds.length; },
            }"
            class="space-y-3"
        >
        {{-- Client list — two layouts over the same data. An 8-column table is
             unreadable on a phone, so below md each client renders as a card;
             from md up the full table takes over. Both include the same
             edit-panel partial, so they can't drift apart. --}}
        <div class="space-y-2 md:hidden">
            @forelse ($clients as $client)
                @php
                    $keUnits = (int) ($client->ke_units_mtd ?? 0);
                    $serpUnits = (int) ($client->serp_units_mtd ?? 0);
                    $spend = $keUnits * $rates['keywords_everywhere'] + $serpUnits * $rates['serp_api'];
                    $isExpanded = $editId === $client->id;
                @endphp
                <div id="row-m-{{ $client->id }}"
                     @class([
                         'overflow-hidden rounded-md border bg-white',
                         'border-orange-300' => $isExpanded,
                         'border-slate-200' => ! $isExpanded,
                         'opacity-60' => $client->is_disabled,
                     ])
                     :class="isSelected(@js($client->id)) ? 'ring-1 ring-orange-300' : ''">
                    <div class="flex items-start gap-2.5 p-3">
                        <div class="pt-1">
                            @if ($client->id === $selfId)
                                <span class="inline-flex h-4 w-4 items-center justify-center" title="Your own account — bulk-disable is locked">
                                    <svg class="h-3.5 w-3.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                                </span>
                            @else
                                <input type="checkbox"
                                       aria-label="Select client {{ $client->email }}"
                                       class="h-4 w-4 rounded border-slate-300 text-orange-600 focus:ring-orange-500"
                                       :checked="isSelected(@js($client->id))"
                                       @change="toggle(@js($client->id))" />
                            @endif
                        </div>
                        <div class="min-w-0 flex-1 space-y-2">
                            @include('admin.clients.partials.identity')
                            @include('admin.clients.partials.status-badges')
                            @include('admin.clients.partials.publishing-status')
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-slate-100 px-3 py-2.5 text-xs">
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Sites</dt>
                            <dd @class(['tabular-nums', 'font-semibold text-slate-800' => $client->websites_count > 0, 'text-slate-400' => $client->websites_count === 0])>{{ $fmtN($client->websites_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Spend MTD</dt>
                            <dd>
                                @if ($spend > 0)
                                    <span class="font-bold tabular-nums text-slate-800">{{ $fmtMoney($spend) }}</span>
                                    <span class="text-[10px] tabular-nums text-slate-400">{{ $fmtN($keUnits) }}·{{ $fmtN($serpUnits) }}</span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Last activity</dt>
                            <dd class="text-slate-600">{{ $relTime($client->last_activity_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Joined</dt>
                            <dd class="text-slate-500">{{ format_user_datetime($client->created_at, 'M j, Y') }}</dd>
                        </div>
                    </dl>

                    <div class="flex items-center gap-2 border-t border-slate-100 bg-slate-50/60 px-3 py-2">
                        <a href="{{ route('admin.clients.index', array_merge(request()->query(), ['edit' => $isExpanded ? 0 : $client->id])) }}#row-m-{{ $client->id }}"
                           @class([
                               'inline-flex flex-1 items-center justify-center gap-1 rounded border px-2 py-1.5 text-[11px] font-semibold',
                               'border-orange-300 bg-orange-50 text-orange-700' => $isExpanded,
                               'border-slate-200 bg-white text-slate-600' => ! $isExpanded,
                           ])>
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            {{ $isExpanded ? 'Close' : 'Edit' }}
                        </a>
                        @if (! $client->is_disabled)
                            <form method="POST" action="{{ route('admin.clients.impersonate', $client) }}" class="flex-1">
                                @csrf
                                <button type="submit"
                                        onclick="return confirm('Sign in as {{ $client->email }}?')"
                                        class="inline-flex w-full items-center justify-center gap-1 rounded border border-slate-200 bg-white px-2 py-1.5 text-[11px] font-semibold text-slate-600">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 6.375a4.125 4.125 0 118.25 0 4.125 4.125 0 01-8.25 0zM2.25 19.125a7.125 7.125 0 0114.25 0v.003l-.001.119a.75.75 0 01-.363.63 13.067 13.067 0 01-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 01-.364-.63l-.001-.122zM18.75 7.5a.75.75 0 00-1.5 0v2.25H15a.75.75 0 000 1.5h2.25v2.25a.75.75 0 001.5 0v-2.25H21a.75.75 0 000-1.5h-2.25V7.5z"/></svg>
                                    Impersonate
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($isExpanded)
                        <div class="border-t border-slate-200 bg-slate-50/60 p-3">
                            @include('admin.clients.partials.edit-panel')
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-md border border-slate-200 bg-white px-3 py-10 text-center">
                    <p class="text-sm text-slate-500">
                        No clients match.
                        @if ($q !== '' || $status !== 'all')
                            <a href="{{ route('admin.clients.index') }}" class="ml-1 font-semibold text-orange-600 hover:underline">Clear filters</a>
                        @endif
                    </p>
                </div>
            @endforelse
        </div>
        <div class="hidden overflow-x-auto rounded-md border border-slate-200 bg-white md:block">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-left">
                    <tr>
                        <th class="w-9 px-3 py-2">
                            <input
                                type="checkbox"
                                aria-label="Select all clients on this page"
                                class="rounded border-slate-300 text-orange-600 focus:ring-orange-500 disabled:opacity-40"
                                :checked="allSelected"
                                :indeterminate.camel="someSelected"
                                @change="togglePage($event.target.checked)"
                                :disabled="allIds.length === 0"
                            />
                        </th>
                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Client</th>
                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Status</th>
                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Publishing</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">Sites</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">Spend MTD</th>
                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Last activity</th>
                        <th class="hidden px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500 2xl:table-cell">Joined</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clients as $client)
                        @php
                            $keUnits = (int) ($client->ke_units_mtd ?? 0);
                            $serpUnits = (int) ($client->serp_units_mtd ?? 0);
                            $spend = $keUnits * $rates['keywords_everywhere'] + $serpUnits * $rates['serp_api'];
                            $isExpanded = $editId === $client->id;
                        @endphp

                        <tr
                            @class(['border-t border-slate-100 align-middle', 'bg-slate-50/40' => $isExpanded, 'opacity-60' => $client->is_disabled])
                            :class="isSelected(@js($client->id)) ? 'bg-orange-50/60' : ''"
                        >
                            <td class="w-9 px-3 py-2.5">
                                @if ($client->id === $selfId)
                                    <span class="inline-flex h-4 w-4 items-center justify-center" title="Your own account — bulk-disable is locked">
                                        <svg class="h-3.5 w-3.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                                    </span>
                                @else
                                    <input
                                        type="checkbox"
                                        aria-label="Select client {{ $client->email }}"
                                        class="rounded border-slate-300 text-orange-600 focus:ring-orange-500"
                                        :checked="isSelected(@js($client->id))"
                                        @change="toggle(@js($client->id))"
                                    />
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                @include('admin.clients.partials.identity')
                            </td>
                            <td class="px-3 py-2.5">
                                @include('admin.clients.partials.status-badges')
                            </td>
                            <td class="max-w-[10rem] px-3 py-2.5">
                                @include('admin.clients.partials.publishing-status')
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <span @class(['tabular-nums', 'font-semibold text-slate-800' => $client->websites_count > 0, 'text-slate-400' => $client->websites_count === 0])>
                                    {{ $fmtN($client->websites_count) }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                @if ($spend > 0)
                                    <div class="font-bold tabular-nums text-slate-800">{{ $fmtMoney($spend) }}</div>
                                    <div class="text-[10px] tabular-nums text-slate-400">
                                        {{ $fmtN($keUnits) }}·{{ $fmtN($serpUnits) }}
                                    </div>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-xs text-slate-600" title="{{ $client->last_activity_at ?? '' }}">
                                {{ $relTime($client->last_activity_at) }}
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-2.5 text-xs text-slate-500 2xl:table-cell" title="{{ format_user_datetime($client->created_at, 'M j, Y g:i A T') }}">
                                {{ format_user_datetime($client->created_at, 'M j, Y') }}
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.clients.index', array_merge(request()->query(), ['edit' => $isExpanded ? 0 : $client->id])) }}#row-{{ $client->id }}"
                                       @class([
                                           'inline-flex items-center gap-1 rounded border px-2 py-1 text-[10px] font-semibold',
                                           'border-orange-300 bg-orange-50 text-orange-700' => $isExpanded,
                                           'border-slate-200 text-slate-600 hover:bg-slate-50' => ! $isExpanded,
                                       ])
                                       title="{{ $isExpanded ? 'Close edit' : 'Edit client' }}">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        {{ $isExpanded ? 'Close' : 'Edit' }}
                                    </a>
                                    @if (! $client->is_disabled)
                                        <form method="POST" action="{{ route('admin.clients.impersonate', $client) }}" class="inline">
                                            @csrf
                                            <button type="submit"
                                                    onclick="return confirm('Sign in as {{ $client->email }}?')"
                                                    class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-50"
                                                    title="Impersonate this client">
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 6.375a4.125 4.125 0 118.25 0 4.125 4.125 0 01-8.25 0zM2.25 19.125a7.125 7.125 0 0114.25 0v.003l-.001.119a.75.75 0 01-.363.63 13.067 13.067 0 01-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 01-.364-.63l-.001-.122zM18.75 7.5a.75.75 0 00-1.5 0v2.25H15a.75.75 0 000 1.5h2.25v2.25a.75.75 0 001.5 0v-2.25H21a.75.75 0 000-1.5h-2.25V7.5z"/></svg>
                                                <span class="hidden 2xl:inline">Impersonate</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>

                        {{-- Inline expanded edit row --}}
                        @if ($isExpanded)
                            <tr id="row-{{ $client->id }}" class="border-t border-slate-100 bg-slate-50/40">
                                <td colspan="9" class="px-3 py-3">
                                    @include('admin.clients.partials.edit-panel')
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-12 text-center">
                                <p class="text-sm text-slate-500">
                                    No clients match.
                                    @if ($q !== '' || $status !== 'all')
                                        <a href="{{ route('admin.clients.index') }}" class="ml-1 font-semibold text-orange-600 hover:underline">Clear filters</a>
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Floating bulk-action bar — appears when one or more rows are
             selected. Submits to admin.clients.bulk with action=disable|enable
             and the selected ids[]. --}}
        <div
            x-show="selected.length > 0"
            x-transition.opacity
            x-cloak
            class="pointer-events-none fixed inset-x-0 bottom-4 z-30 flex justify-center px-4"
        >
            <form
                method="POST"
                action="{{ route('admin.clients.bulk') }}"
                class="pointer-events-auto flex w-full max-w-2xl flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white p-2 shadow-lg ring-1 ring-black/5"
                @submit="$nextTick(() => { selected = [] })"
            >
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
                @foreach (request()->query() as $k => $v)
                    @if (is_scalar($v))
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endif
                @endforeach
                <div class="flex w-full items-center gap-2 pl-2 text-xs text-slate-700 sm:w-auto sm:flex-1">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-orange-100 font-bold text-orange-700 tabular-nums" x-text="selected.length"></span>
                    <span class="font-medium">
                        <span x-text="selected.length === 1 ? 'client' : 'clients'"></span> selected
                    </span>
                    <button
                        type="button"
                        @click="clear()"
                        class="ml-2 text-[11px] font-semibold text-slate-500 hover:text-slate-800 hover:underline"
                    >
                        Clear
                    </button>
                </div>
                <button
                    type="submit"
                    name="action"
                    value="enable"
                    class="inline-flex flex-1 items-center justify-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 sm:flex-none"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Enable
                </button>
                <button
                    type="submit"
                    name="action"
                    value="disable"
                    class="inline-flex flex-1 items-center justify-center gap-1 rounded-md bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700 sm:flex-none"
                    @click="if (!confirm('Disable ' + selected.length + ' client' + (selected.length === 1 ? '' : 's') + '? They will be blocked from logging in.')) $event.preventDefault();"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728A9 9 0 005.636 5.636"/></svg>
                    Disable
                </button>
            </form>
        </div>

        </div>{{-- /x-data --}}

        {{-- Pagination --}}
        @if ($clients->hasPages())
            <div class="flex flex-col gap-2 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <span>
                    Showing {{ $clients->firstItem() }}–{{ $clients->lastItem() }} of {{ $fmtN($clients->total()) }}
                </span>
                <div>{{ $clients->links() }}</div>
            </div>
        @endif
    </div>
</x-layouts.app>
