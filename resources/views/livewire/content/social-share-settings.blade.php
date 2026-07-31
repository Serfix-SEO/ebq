<div>
@if ($hasWebsite && $sharingEnabled && ($facebookConfigured || $xConfigured || $pinterestConfigured))
    {{-- No `mt-6` and no card header any more: since 2026-07-31 this is the
         whole /content/social page, which supplies its own h1. Repeating the
         title inside the card read as a duplicated heading. --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        {{-- Flash --}}
        @if (session('social-status'))
            <div class="mb-4 flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('social-status') }}
            </div>
        @endif
        @if (session('social-error'))
            <div class="mb-4 flex items-center gap-2.5 rounded-xl border border-error/30 bg-error/5 px-4 py-3 text-sm font-semibold text-slate-700 dark:border-error/40 dark:text-slate-200">
                <svg class="h-4 w-4 shrink-0 text-error" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                {{ session('social-error') }}
            </div>
        @endif

        {{-- Facebook page picker (account manages several Pages) --}}
        @if (! empty($pendingPages))
            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900/60 dark:bg-sky-950/40">
                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Which Facebook Page should we post to?') }}</p>
                <div class="mt-2.5 flex flex-wrap gap-2">
                    @foreach ($pendingPages as $page)
                        <button type="button" wire:click="chooseFacebookPage(@js((string) $page['id']))" wire:key="fbp-{{ $page['id'] }}"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:border-sky-400 hover:text-sky-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                            {{ $page['name'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Pinterest board picker (account has several boards) --}}
        @if (! empty($pendingBoards))
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/60 dark:bg-rose-950/40">
                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Which Pinterest board should we pin to?') }}</p>
                <div class="mt-2.5 flex flex-wrap gap-2">
                    @foreach ($pendingBoards as $board)
                        <button type="button" wire:click="choosePinterestBoard(@js((string) $board['id']))" wire:key="ptb-{{ $board['id'] }}"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:border-rose-400 hover:text-rose-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                            {{ $board['name'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="space-y-3">
            @foreach ([
                ['provider' => 'facebook', 'label' => __('Facebook Page'), 'configured' => $facebookConfigured, 'route' => 'social.facebook.redirect', 'hint' => __('Posts go to your business Page.')],
                ['provider' => 'x', 'label' => 'X', 'configured' => $xConfigured, 'route' => 'social.x.redirect', 'hint' => __('Posts go to your X profile.')],
                ['provider' => 'pinterest', 'label' => 'Pinterest', 'configured' => $pinterestConfigured, 'route' => 'social.pinterest.redirect', 'hint' => __('Pins the article image to a board you choose. Needs a business account.')],
            ] as $p)
                @continue(! $p['configured'])
                @php $account = $accounts[$p['provider']] ?? null; @endphp
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-100 p-3.5 dark:border-slate-800" wire:key="social-{{ $p['provider'] }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $p['label'] }}</span>
                            @if ($account !== null && $account->status === \App\Models\ContentSocialAccount::STATUS_CONNECTED)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-px text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    {{ $account->display_name ?: __('Connected') }}
                                </span>
                            @elseif ($account !== null)
                                <span class="rounded-full bg-rose-100 px-2 py-px text-[11px] font-bold text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">{{ __('Needs reconnect') }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            @if ($account?->last_error)
                                {{ $account->last_error }}
                            @elseif ($account?->last_posted_at)
                                {{ __('Last shared :time', ['time' => $account->last_posted_at->diffForHumans()]) }}
                            @else
                                {{ $p['hint'] }}
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2.5">
                        @if ($account !== null && $account->status === \App\Models\ContentSocialAccount::STATUS_CONNECTED)
                            <button type="button" wire:click="toggleShare('{{ $account->id }}')" role="switch" aria-checked="{{ $account->share_enabled ? 'true' : 'false' }}" title="{{ __('Auto-share on or off') }}"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $account->share_enabled ? 'bg-orange-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition {{ $account->share_enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </button>
                            <button type="button" wire:click="disconnect('{{ $account->id }}')" wire:confirm="{{ __('Disconnect this account? Articles will no longer be shared there.') }}"
                                class="text-xs font-semibold text-slate-400 hover:text-error">{{ __('Disconnect') }}</button>
                        @elseif ($account !== null)
                            <a href="{{ route($p['route']) }}" class="inline-flex items-center gap-1.5 rounded-xl bg-orange-600 px-4 py-2 text-sm font-bold text-white hover:bg-orange-700">{{ __('Reconnect') }}</a>
                            <button type="button" wire:click="disconnect('{{ $account->id }}')" wire:confirm="{{ __('Disconnect this account? Articles will no longer be shared there.') }}"
                                class="text-xs font-semibold text-slate-400 hover:text-error">{{ __('Disconnect') }}</button>
                        @else
                            <a href="{{ route($p['route']) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-700 dark:border-slate-700 dark:text-slate-200 dark:hover:text-orange-300">
                                {{ __('Connect') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
</div>
