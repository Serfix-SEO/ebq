@php
    $buckets = [
        'missing' => ['label' => 'Missing', 'hint' => 'They rank, you don’t'],
        'weak' => ['label' => 'Weak', 'hint' => 'You rank, but poorly'],
        'strength' => ['label' => 'Strengths', 'hint' => 'You lead'],
        'shared' => ['label' => 'Shared', 'hint' => 'Both target it'],
    ];
    $summary = $analysis?->summary ?? [];
    $hasGsc = (bool) $website?->hasGsc();
    $verified = $analysis && $analysis->verify_status === 'completed';
    // Show position columns once we have positions — from GSC or verification.
    $showPositions = $hasGsc || $verified;
    // After verification a no-GSC site can split weak/strength too.
    $visibleTabs = $showPositions ? ['missing', 'weak', 'strength'] : ['missing', 'shared'];
@endphp

<div @if($this->isPolling() || $this->isVerifying()) wire:poll.3000ms="poll" @endif>
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
        @if (! $website)
            <p class="text-sm text-slate-500 dark:text-slate-400">Select a website to run a gap analysis.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @for ($i = 0; $i < $maxCompetitors; $i++)
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Competitor {{ $i + 1 }}{{ $i === 0 ? '' : ' (optional)' }}</label>
                        <input type="text" wire:model="competitors.{{ $i }}" placeholder="competitor.com"
                            class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    </div>
                @endfor
                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Country</label>
                    <select wire:model="country" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        @foreach ($countryOptions as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button" wire:click="run" @if($this->isPolling()) disabled @endif
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                    @if ($this->isPolling())
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        Analysing…
                    @else
                        Run gap analysis
                    @endif
                </button>
                @if ($this->isPolling() && $analysis)
                    <span class="text-xs text-slate-400">Collecting {{ $analysis->completed_requests }}/{{ $analysis->total_requests }} sources…</span>
                @endif
            </div>

            @unless ($hasGsc)
                <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">No Search Console connected — keywords reflect what each site’s content <em>targets</em>, not confirmed rankings. Connect Search Console to split shared keywords into Weak vs Strengths and unlock position data.</p>
            @endunless
            @if ($errorMessage)
                <p class="mt-3 text-sm text-red-600 dark:text-red-400">
                    {{ $errorMessage }}
                    @if ($upgradeUrl)
                        <a href="{{ $upgradeUrl }}" class="ml-1 font-semibold underline">Upgrade</a>
                    @endif
                </p>
            @endif
            @if ($analysis?->reprocessed_at)
                <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">Upgraded with your Search Console data.</p>
            @endif
            @if ($trackNotice)
                <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ $trackNotice }}</p>
            @endif
        @endif
    </div>

    @if ($analysis && $analysis->status === 'completed')
        <div class="mt-5">
            <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-700">
                @foreach ($visibleTabs as $key)
                    <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                            'border-indigo-600 text-indigo-600 dark:text-indigo-400' => $tab === $key,
                            'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
                        ])>
                        {{ $buckets[$key]['label'] }}
                        <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-xs text-slate-500 dark:bg-slate-700">{{ $summary[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            @if ($tab === 'missing')
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-900/40">
                    @if ($this->isVerifying())
                        <span class="flex items-center gap-2 text-xs text-slate-500">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            Verifying live rankings… {{ $analysis->verify_done }}/{{ $analysis->verify_total }}
                        </span>
                    @else
                        <button type="button" wire:click="verifyRankings"
                            class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700 dark:bg-slate-200 dark:text-slate-900">
                            Verify with live rankings
                        </button>
                    @endif
                    <span class="text-[11px] text-slate-400">Confirms the competitor really ranks (top&nbsp;10) and captures real positions.</span>
                </div>
                @if ($verified && $analysis->verify_error)
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">Partial: {{ $analysis->verify_error }} ({{ $analysis->verify_done }}/{{ $analysis->verify_total }} checked.)</p>
                @endif
                @if ($verifyNotice)
                    <p class="mt-2 text-xs text-slate-500">{{ $verifyNotice }}</p>
                @endif
            @endif

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-400">{{ $buckets[$tab]['hint'] ?? '' }} · {{ $total }} keyword(s)</p>
                <div class="flex items-center gap-3">
                    @if ($tab === 'missing' && $verified)
                        <label class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                            <input type="checkbox" wire:model.live="confirmedOnly" class="rounded border-slate-300">
                            Confirmed only
                        </label>
                    @endif
                    <input type="text" wire:model.live.debounce.400ms="filterText" placeholder="Filter keywords…"
                        class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                </div>
            </div>

            <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/50">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <th class="px-4 py-3">Keyword</th>
                            <th class="px-4 py-3">Opportunity</th>
                            <th class="px-4 py-3">Volume</th>
                            <th class="px-4 py-3">CPC</th>
                            @if ($showPositions)<th class="px-4 py-3">Your position</th>@endif
                            <th class="px-4 py-3">Competitors</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">
                                    {{ $row->keyword }}
                                    <div class="mt-0.5 inline-flex items-center gap-2 text-[11px]">
                                        <button type="button" wire:click="sendToVolume('{{ $row->id }}')" class="text-indigo-600 hover:underline dark:text-indigo-400">Volume</button>
                                        <button type="button" wire:click="sendToIdeas('{{ $row->id }}')" class="text-indigo-600 hover:underline dark:text-indigo-400">Ideas</button>
                                        <button type="button" wire:click="track(@js($row->keyword))" class="text-slate-500 hover:underline dark:text-slate-400">Track</button>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $s = $row->opportunity_score;
                                        $cls = $s === null ? 'bg-slate-100 text-slate-500' : ($s >= 70 ? 'bg-emerald-100 text-emerald-700' : ($s >= 40 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'));
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $cls }}"
                                        title="{{ is_array($row->score_components) ? json_encode($row->score_components) : '' }}">{{ $s ?? '—' }}</span>
                                    @if (isset($refinedRows[$row->id]))
                                        <span class="ml-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">refined ✓</span>
                                    @else
                                        <button type="button" wire:click="computeLive('{{ $row->id }}')" wire:target="computeLive('{{ $row->id }}')" wire:loading.attr="disabled" class="ml-1 text-[11px] text-indigo-500 hover:underline">
                                            <span wire:loading.remove wire:target="computeLive('{{ $row->id }}')">refine</span>
                                            <span wire:loading wire:target="computeLive('{{ $row->id }}')">…</span>
                                        </button>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->search_volume !== null ? number_format($row->search_volume) : '—' }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->cpc !== null ? '$'.number_format($row->cpc, 2) : '—' }}</td>
                                @if ($showPositions)<td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->our_position !== null ? number_format($row->our_position, 1) : '—' }}</td>@endif
                                <td class="px-4 py-3 text-xs text-slate-500">
                                    @if ($row->competitor_position !== null)
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">Competitor #{{ $row->competitor_position }}</span>
                                    @elseif ($row->verified_at !== null)
                                        <span class="text-slate-400" title="Checked — competitor not in the top 10">unconfirmed</span>
                                    @else
                                        {{ is_array($row->competitor_domains) ? implode(', ', $row->competitor_domains) : '' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $showPositions ? 6 : 5 }}" class="px-4 py-8 text-center text-sm text-slate-400">No keywords in this bucket.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($totalPages > 1)
                <div class="mt-3 flex items-center justify-center gap-2 text-sm">
                    <button type="button" wire:click="setPage({{ $page - 1 }})" @disabled($page <= 1) class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40">Prev</button>
                    <span class="text-slate-500">Page {{ $page }} of {{ $totalPages }}</span>
                    <button type="button" wire:click="setPage({{ $page + 1 }})" @disabled($page >= $totalPages) class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40">Next</button>
                </div>
            @endif
        </div>
    @endif
</div>
