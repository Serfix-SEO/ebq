{{-- Shared single-keyword volume report body. Expects:
       $result = ['keyword','country','volume','cpc','currency','competition','trend'=>[…],'cached','fetched_at'] --}}
@php
    $volume = $result['volume'] ?? null;
    $cpc = $result['cpc'] ?? null;
    $currency = $result['currency'] ?? 'USD';
    $competition = $result['competition'] ?? null;
    $trend = is_array($result['trend'] ?? null) ? $result['trend'] : [];

    $compLabel = $competition === null ? '—'
        : ($competition < 0.34 ? 'Low' : ($competition < 0.67 ? 'Medium' : 'High'));
    $compTone = $competition === null ? 'text-slate-500'
        : ($competition < 0.34 ? 'text-emerald-600' : ($competition < 0.67 ? 'text-amber-600' : 'text-rose-600'));

    // Normalize the 12-month trend into a simple bar series.
    $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $series = [];
    foreach ($trend as $t) {
        if (! is_array($t)) {
            continue;
        }
        $series[] = [
            'value' => (int) ($t['value'] ?? 0),
            'label' => isset($t['month']) && isset($months[(int) $t['month']]) ? $months[(int) $t['month']] : '',
        ];
    }
    $peak = collect($series)->max('value') ?: 1;
@endphp

<section>
    <div class="grid gap-4 sm:grid-cols-3">
        {{-- Headline volume --}}
        <div class="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm ring-1 {{ $volume !== null ? 'ring-orange-200' : 'ring-slate-200' }}">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Monthly searches</p>
            <p class="mt-1 text-5xl font-extrabold tabular-nums {{ $volume !== null ? 'text-orange-600' : 'text-slate-400' }}">{{ $volume !== null ? number_format($volume) : '—' }}</p>
            <p class="mt-2 text-xs font-medium text-slate-500">{{ $volume !== null ? 'avg. searches / month' : 'No volume data for this keyword' }}</p>
        </div>

        {{-- CPC + competition --}}
        <div class="sm:col-span-2 grid grid-cols-2 gap-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Cost per click</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $cpc !== null ? $currency.' '.number_format((float) $cpc, 2) : '—' }}</p>
                <p class="mt-1 text-xs text-slate-500">Google Ads top-of-page bid</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Competition</p>
                <p class="mt-1 text-2xl font-bold {{ $compTone }}">{{ $compLabel }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $competition !== null ? 'Index '.number_format((float) $competition, 2) : 'Advertiser density' }}</p>
            </div>
            <div class="col-span-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <p class="px-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">12-month trend</p>
                @if (count($series))
                    <div class="mt-2 flex h-20 items-end gap-1 px-1">
                        @foreach ($series as $pt)
                            <div class="flex flex-1 flex-col items-center gap-1">
                                <div class="w-full rounded-t bg-orange-400/80" style="height: {{ max(4, (int) round(($pt['value'] / $peak) * 64)) }}px" title="{{ number_format($pt['value']) }}"></div>
                                <span class="text-[8px] text-slate-400">{{ $pt['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="px-1 py-4 text-center text-xs text-slate-400">No trend data available.</p>
                @endif
            </div>
        </div>
    </div>

    <p class="mt-5 text-[11px] leading-relaxed text-slate-400">
        Volume, CPC and competition from Keywords Everywhere (Google Keyword Planner data) for
        <strong class="text-slate-500">{{ $result['keyword'] ?? '' }}</strong> · {{ \App\Support\KeywordsEverywhereCountries::label($result['country'] ?? 'global') }}@if (! empty($result['fetched_at'])) · updated {{ \Carbon\Carbon::parse($result['fetched_at'])->diffForHumans() }}@endif.
    </p>
</section>
