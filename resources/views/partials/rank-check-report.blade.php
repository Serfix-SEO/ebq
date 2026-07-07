{{-- Shared guest rank-check report body. Expects:
       $result = ['keyword','domain','country','position','found_url','depth',
                  'scanned','results'=>[['position','title','link','domain','snippet','is_target'],…],'checked_at'] --}}
@php
    $position = $result['position'] ?? null;
    $depth = (int) ($result['depth'] ?? 100);
    $domain = $result['domain'] ?? '';
    $foundUrl = $result['found_url'] ?? null;
    $rows = is_array($result['results'] ?? null) ? $result['results'] : [];

    // Tone for the headline position card.
    $tone = $position === null
        ? ['ring' => 'ring-slate-200', 'bg' => 'bg-slate-50', 'text' => 'text-slate-500', 'label' => __('Not ranking in the top :depth', ['depth' => $depth])]
        : ($position <= 3
            ? ['ring' => 'ring-emerald-200', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'label' => __('Top 3 — excellent visibility')]
            : ($position <= 10
                ? ['ring' => 'ring-orange-200', 'bg' => 'bg-orange-50', 'text' => 'text-orange-600', 'label' => __('Page 1 of Google')]
                : ($position <= 20
                    ? ['ring' => 'ring-amber-200', 'bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'label' => __('Page 2 — close to the first page')]
                    : ['ring' => 'ring-slate-200', 'bg' => 'bg-slate-50', 'text' => 'text-slate-600', 'label' => __('Ranking, but deep in the results')])));
@endphp

<section>
    {{-- ── Headline position ─────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm ring-1 {{ $tone['ring'] }}">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Current position') }}</p>
            <p class="mt-1 text-5xl font-extrabold tabular-nums {{ $tone['text'] }}">{{ $position !== null ? '#'.$position : '—' }}</p>
            <p class="mt-2 text-xs font-medium text-slate-500">{{ $tone['label'] }}</p>
        </div>

        <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div class="col-span-2">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Keyword') }}</dt>
                    <dd class="mt-0.5 font-semibold text-slate-900">{{ $result['keyword'] ?? '' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Domain') }}</dt>
                    <dd class="mt-0.5 break-all font-medium text-slate-700">{{ $domain }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Country') }}</dt>
                    <dd class="mt-0.5 font-medium text-slate-700">{{ ! empty($result['country']) ? strtoupper($result['country']) : __('Default') }}</dd>
                </div>
                @if ($foundUrl)
                    <div class="col-span-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Ranking URL') }}</dt>
                        <dd class="mt-0.5">
                            <a href="{{ $foundUrl }}" target="_blank" rel="noopener noreferrer" class="break-all text-orange-600 hover:underline">{{ $foundUrl }}</a>
                        </dd>
                    </div>
                @endif
            </dl>
            @if ($position === null)
                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-800 ring-1 ring-amber-100">
                    <strong>{{ $domain }}</strong> {{ __('wasn’t found in the top :depth organic results for this keyword.', ['depth' => $depth]) }} {{ __('The competitors below are who you’re up against — sign up to track your climb as you optimize.') }}
                </p>
            @endif
        </div>
    </div>

    {{-- ── Top results ───────────────────────────────────────────────── --}}
    <div class="mt-6">
        <h2 class="mb-2 text-sm font-bold text-slate-900">{{ __('Top :count organic results', ['count' => count($rows)]) }}</h2>
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <ul class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <li @class([
                        'flex items-start gap-3 p-3.5',
                        'bg-orange-50/60' => $row['is_target'] ?? false,
                    ])>
                        <span @class([
                            'mt-0.5 flex h-7 w-7 flex-none items-center justify-center rounded-lg text-xs font-bold tabular-nums',
                            'bg-orange-600 text-white' => $row['is_target'] ?? false,
                            'bg-slate-100 text-slate-600' => ! ($row['is_target'] ?? false),
                        ])>{{ $row['position'] }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ $row['title'] ?: $row['domain'] }}</p>
                                @if ($row['is_target'] ?? false)
                                    <span class="flex-none rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-700">{{ __('You') }}</span>
                                @endif
                            </div>
                            <a href="{{ $row['link'] }}" target="_blank" rel="noopener noreferrer" class="block truncate text-xs text-emerald-700 hover:underline">{{ $row['link'] }}</a>
                            @if (! empty($row['snippet']))
                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-slate-500">{{ $row['snippet'] }}</p>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-slate-500">{{ __('No organic results were returned for this keyword.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    <p class="mt-5 text-[11px] leading-relaxed text-slate-400">
        {{ __('Live Google organic results via Serper, scanned to depth :depth', ['depth' => $depth]) }}@if (! empty($result['checked_at'])) · {{ __('checked :time', ['time' => \Carbon\Carbon::parse($result['checked_at'])->diffForHumans()]) }}@endif.
        {{ __('A single snapshot can differ from your own browser by location, personalization and device — sign up for tracked, de-personalized positions over time.') }}
    </p>
</section>
