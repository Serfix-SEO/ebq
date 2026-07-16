{{-- Link-risk warning panel — the interpretation layer over the backlink
     profile. Renders only when the toxicity scorer found something.
     Params: $risk (payload link_risk), $dark (bool), $disavowUrl (?string). --}}
@php
    $level = $risk['level'] ?? null;
    $d = $dark ?? false;
@endphp
@if ($level !== null)
    @php
        $high = $level === 'high';
        $frame = $high
            ? 'border-l-4 border-rose-500 bg-rose-50 ring-1 ring-rose-200'.($d ? ' dark:bg-rose-500/10 dark:ring-rose-900' : '')
            : 'border-l-4 border-amber-500 bg-amber-50 ring-1 ring-amber-200'.($d ? ' dark:bg-amber-500/10 dark:ring-amber-900' : '');
        $heading = $high ? 'text-rose-900'.($d ? ' dark:text-rose-200' : '') : 'text-amber-900'.($d ? ' dark:text-amber-200' : '');
        $body = $high ? 'text-rose-800'.($d ? ' dark:text-rose-300' : '') : 'text-amber-800'.($d ? ' dark:text-amber-300' : '');
    @endphp
    <div class="rounded-2xl p-6 {{ $frame }}">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-lg font-bold {{ $heading }}">
                    <svg class="h-5 w-5 flex-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    {{ $high ? __('High link risk detected') : __('Elevated link risk') }}
                </p>
                <ul class="mt-3 space-y-1.5 text-sm leading-6 {{ $body }}">
                    @if (($risk['toxic_anchor_backlinks'] ?? 0) > 0)
                        <li>• {{ __(':n backlinks carry link-selling or spam anchors', ['n' => number_format($risk['toxic_anchor_backlinks'])]) }}</li>
                    @endif
                    @if (($risk['toxic_domain_count'] ?? 0) > 0)
                        <li>• {{ __(':n referring domains flagged as toxic (link networks, spam sources)', ['n' => number_format($risk['toxic_domain_count'])]) }}</li>
                    @endif
                    @if (! empty($risk['over_optimized']))
                        <li>• {{ __(':pct% exact-match anchors — over-optimization is a known penalty-risk signal', ['pct' => (int) ($risk['exact_pct'] ?? 0)]) }}</li>
                    @endif
                    @if (($risk['suspicious_domain_count'] ?? 0) >= 5)
                        <li>• {{ __(':n more domains look suspicious (disposable TLDs with no authority)', ['n' => number_format($risk['suspicious_domain_count'])]) }}</li>
                    @endif
                </ul>
                @if (! empty($risk['toxic_anchor_examples']))
                    <div class="mt-3 space-y-1">
                        @foreach ($risk['toxic_anchor_examples'] as $ex)
                            <p class="truncate font-mono text-xs {{ $body }} opacity-80">
                                “{{ $ex['anchor'] }}” — {{ number_format($ex['backlinks']) }} {{ __('links from') }} {{ number_format($ex['referring_domains']) }} {{ __('domains') }}
                            </p>
                        @endforeach
                    </div>
                @endif
                <p class="mt-3 text-xs {{ $body }} opacity-80">
                    {{ __('Flagged rows are marked in the tables below. Review them, then consider disavowing the toxic domains so search engines ignore those links.') }}
                </p>
            </div>
            @if (! empty($disavowUrl) && ($risk['toxic_domain_count'] ?? 0) > 0)
                <a href="{{ $disavowUrl }}"
                   class="inline-flex flex-none items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition {{ $high ? 'bg-rose-600 hover:bg-rose-700' : 'bg-amber-600 hover:bg-amber-700' }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" /></svg>
                    {{ __('Download disavow file') }}
                </a>
            @endif
        </div>
    </div>
@endif
