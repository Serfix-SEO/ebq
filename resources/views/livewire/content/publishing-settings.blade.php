<div>
    @if ($hasWebsite && $plan !== null)
        <div class="mx-auto w-full max-w-6xl">
            <x-content.connect-gsc />
        </div>
        <div class="mx-auto mt-6 w-full max-w-6xl rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </span>
                    <div>
                        <h2 class="text-lg font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Where your articles publish') }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Connect your site once — approved articles go live on their scheduled day, automatically.') }}</p>
                    </div>
                </div>
                @if ($waiting > 0 && $integrations->where('status', 'connected')->isEmpty())
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ trans_choice(':n article waiting to publish|:n articles waiting to publish', $waiting, ['n' => $waiting]) }}</span>
                @endif
            </div>

            @if (session('publishing-status'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
                    x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                    class="relative mt-4 flex items-start gap-3 overflow-hidden rounded-2xl border border-success/25 bg-white p-4 ps-5 shadow-sm ring-1 ring-success/5 dark:border-success/25 dark:bg-slate-900">
                    <span class="absolute inset-y-0 start-0 w-1 bg-gradient-to-b from-success to-emerald-600"></span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-success/10 text-success">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ session('publishing-status') }}</p>
                    </div>
                    <button type="button" @click="show = false" class="shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Dismiss') }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            @endif

            {{-- Auto-publish is ON but nothing is connected (no integration at all,
                 or every one is errored/pending). The setting silently does nothing
                 until a destination verifies — say so prominently. --}}
            @if ($plan->auto_publish && $integrations->where('status', 'connected')->isEmpty())
                <div class="mt-4 flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800/60 dark:bg-amber-950/40">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('Auto-publish is on, but no destination is connected') }}</p>
                        <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-300">{{ __('Approved articles will be held and won\'t go live until you connect a publishing destination below.') }}</p>
                    </div>
                </div>
            @endif

            {{-- Connected platforms --}}
            @if ($integrations->isNotEmpty())
                <div class="mt-5 space-y-2.5">
                    @foreach ($integrations as $integration)
                        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 dark:border-slate-700 dark:bg-slate-800/40" wire:key="int-{{ $integration->id }}">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $integration->isConnected() ? 'bg-success/10 text-success' : 'bg-error/10 text-error' }}">
                                @if ($integration->isConnected())
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                @else
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 9v4m0 4h.01"/></svg>
                                @endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-bold text-slate-900 dark:text-slate-100">
                                    {{-- Laravel is a webhook under the hood; the flavour recorded at
                                         connect time is what lets us name it properly here. --}}
                                    @if ($integration->platform === \App\Models\ContentIntegration::PLATFORM_WEBHOOK && ($integration->config['flavor'] ?? null) === \App\Livewire\Content\PublishingSettings::FLAVOR_LARAVEL)
                                        {{ __('Laravel') }}
                                    @elseif ($integration->platform === \App\Models\ContentIntegration::PLATFORM_WEBHOOK)
                                        {{ __('Custom (webhook)') }}
                                    @else
                                        {{ $integration->platformLabel() }}
                                    @endif
                                </div>
                                <div class="truncate text-xs text-slate-500 dark:text-slate-400">
                                    @if ($integration->isConnected())
                                        {{ __('Connected') }}@if($integration->last_verified_at) · {{ __('checked') }} {{ $integration->last_verified_at->diffForHumans() }}@endif
                                    @else
                                        <span class="text-error">{{ $integration->last_error ?: __('Needs attention') }}</span>
                                    @endif
                                </div>
                            </div>
                            <button type="button" wire:click="reverify('{{ $integration->id }}')" class="text-xs font-semibold text-slate-500 hover:text-orange-600 dark:text-slate-400">
                                {{ __('Re-check') }}
                            </button>
                            <button type="button" wire:click="disconnect('{{ $integration->id }}')" wire:confirm="{{ __('Disconnect this platform? Scheduled articles will wait until you reconnect.') }}" class="text-xs font-semibold text-slate-400 hover:text-error">
                                {{ __('Disconnect') }}
                            </button>
                        </div>

                        {{-- A WordPress integration that has gone bad: show the
                             walkthrough right here. The guide used to live only
                             inside the connect panel, so an ALREADY-connected
                             site that later broke displayed the error with no
                             instructions and no way to act on it (2026-08-08) —
                             and the fix is nearly always "make a fresh
                             application password", which is step 2 below. --}}
                        @if (! $integration->isConnected() && $integration->platform !== \App\Models\ContentIntegration::PLATFORM_WEBHOOK)
                            <div wire:key="fix-{{ $integration->id }}">
                                <button type="button" wire:click="reconnect('{{ $integration->id }}')"
                                    class="mb-2 inline-flex items-center gap-1.5 rounded-xl bg-orange-600 px-4 py-2 text-sm font-bold text-white hover:bg-orange-700">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-4.992 4.992l3.181-3.183a8.25 8.25 0 00-13.803 3.7M4.031 9.865v4.99m0 0h4.99m-4.99 0l3.181 3.183a8.25 8.25 0 0013.803-3.7"/></svg>
                                    {{ __('Fix the connection') }}
                                </button>
                                {{-- The illustrated walkthrough is WordPress-specific; other
                                     platforms get their step guide inside the connect panel. --}}
                                @if ($integration->platform === \App\Models\ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD || $integration->platform === \App\Models\ContentIntegration::PLATFORM_WORDPRESS)
                                    @include('partials.wp-connect-guide', ['guideForceOpen' => true])
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Hands-off mode --}}
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700">
                    <div>
                        <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Hands-off publishing') }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Finished articles go live by themselves after a :n-hour review window — no approval click needed.', ['n' => (int) $plan->review_hours]) }}</div>
                    </div>
                    <button type="button" wire:click="toggleAutoPublish"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $plan->auto_publish ? 'bg-success' : 'bg-slate-300 dark:bg-slate-700' }}">
                        <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition {{ $plan->auto_publish ? 'translate-x-5' : 'translate-x-1' }}"></span>
                    </button>
                </div>
            @endif

            {{-- Connect a platform --}}
            @if (! $showConnect)
                <button type="button" wire:click="$set('showConnect', true)" class="mt-5 inline-flex items-center gap-1.5 rounded-xl {{ $integrations->isEmpty() ? 'bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110' : 'border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    {{ $integrations->isEmpty() ? __('Connect your site') : __('Add another platform') }}
                </button>
            @else
                <div class="mt-5 rounded-2xl border border-slate-200 p-5 dark:border-slate-700">
                    @php
                        $isLaravel = $platform === \App\Livewire\Content\PublishingSettings::FLAVOR_LARAVEL;
                        $isWebhook = $platform === \App\Models\ContentIntegration::PLATFORM_WEBHOOK;
                        $isShopify = $platform === \App\Models\ContentIntegration::PLATFORM_SHOPIFY;
                        $isWebflow = $platform === \App\Models\ContentIntegration::PLATFORM_WEBFLOW;
                        $isWix = $platform === \App\Models\ContentIntegration::PLATFORM_WIX;
                        $isSanity = $platform === \App\Models\ContentIntegration::PLATFORM_SANITY;
                        $isHubSpot = $platform === \App\Models\ContentIntegration::PLATFORM_HUBSPOT;
                        $isWordPress = ! $isLaravel && ! $isWebhook && ! $isShopify && ! $isWebflow && ! $isWix && ! $isSanity && ! $isHubSpot;
                        $tiles = [
                            [\App\Models\ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD, 'WordPress', $isWordPress],
                            [\App\Models\ContentIntegration::PLATFORM_SHOPIFY, 'Shopify', $isShopify],
                            [\App\Models\ContentIntegration::PLATFORM_WEBFLOW, 'Webflow', $isWebflow],
                            [\App\Models\ContentIntegration::PLATFORM_WIX, 'Wix', $isWix],
                            [\App\Models\ContentIntegration::PLATFORM_HUBSPOT, 'HubSpot', $isHubSpot],
                            [\App\Models\ContentIntegration::PLATFORM_SANITY, 'Sanity', $isSanity],
                            [\App\Livewire\Content\PublishingSettings::FLAVOR_LARAVEL, 'Laravel', $isLaravel],
                            [\App\Models\ContentIntegration::PLATFORM_WEBHOOK, __('Custom (webhook)'), $isWebhook],
                        ];
                    @endphp

                    @if ($pendingTarget !== null)
                        {{-- Step 2 of connect: credentials verified, the destination
                             still needs a choice (which blog / collection / dataset).
                             The integration stays pending until this resolves. --}}
                        <div>
                            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('One more step') }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $pendingTarget['label'] }}</p>

                            @error('connect') <p class="mt-3 rounded-xl bg-error/10 px-4 py-3 text-sm font-medium text-error">{{ $message }}</p> @enderror

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <select wire:model="chosenTargetId"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($pendingTarget['options'] as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mt-4 flex items-center justify-end gap-2">
                                <button type="button" wire:click="$set('showConnect', false)" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                                <button type="button" wire:click="chooseTarget" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                                    <span wire:loading.remove wire:target="chooseTarget">{{ __('Continue') }}</span>
                                    <span wire:loading wire:target="chooseTarget">{{ __('Checking…') }}</span>
                                </button>
                            </div>
                        </div>
                    @else
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($tiles as [$tilePlatform, $tileLabel, $tileActive])
                            <button type="button" wire:click="selectPlatform('{{ $tilePlatform }}')" wire:key="tile-{{ $tilePlatform }}"
                                class="flex items-center gap-2 rounded-xl border px-3 py-2.5 text-start text-sm font-bold transition {{ $tileActive ? 'border-orange-500 bg-orange-50 text-orange-700 ring-1 ring-orange-500 dark:bg-orange-950 dark:text-orange-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-[11px] font-extrabold {{ $tileActive ? 'bg-orange-600 text-white' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">{{ mb_substr($tileLabel === __('Custom (webhook)') ? 'API' : $tileLabel, 0, $tileLabel === __('Custom (webhook)') ? 3 : 2) }}</span>
                                <span class="truncate">{{ $tileLabel }}</span>
                            </button>
                        @endforeach
                    </div>

                    @error('connect') <p class="mt-3 rounded-xl bg-error/10 px-4 py-3 text-sm font-medium text-error">{{ $message }}</p> @enderror

                    @if ($isShopify)
                        @include('partials.content-connect.shopify')
                    @elseif ($isWebflow)
                        @include('partials.content-connect.webflow')
                    @elseif ($isWix)
                        @include('partials.content-connect.wix')
                    @elseif ($isSanity)
                        @include('partials.content-connect.sanity')
                    @elseif ($isHubSpot)
                        @include('partials.content-connect.hubspot')
                    @elseif ($isWordPress)
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ __('In WordPress go to Users → Profile → Application Passwords, create one named "Serfix", and paste it here. The account needs to be an Author or above.') }}</p>
                        {{-- The one-line instruction above assumes the reader already
                             knows what an application password is; this is the
                             illustrated walkthrough for everyone else. --}}
                        @include('partials.wp-connect-guide', ['guideForceOpen' => $errors->has('connect')])
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Site URL') }}</label>
                                <input wire:model="wpSiteUrl" type="text" placeholder="https://your-site.com"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                @error('wpSiteUrl') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('WordPress username') }}</label>
                                <input wire:model="wpUsername" type="text" autocomplete="off"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                @error('wpUsername') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Application password') }}</label>
                                <input wire:model="wpAppPassword" type="password" autocomplete="new-password"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                @error('wpAppPassword') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @else
                        @if ($isLaravel)
                            {{-- Laravel integration guide. The customer installs a package that
                                 already speaks our signed-webhook format, so the only thing they
                                 have to carry across is the secret below. --}}
                            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
                                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Install the Serfix package on your Laravel site') }}</p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('It receives each article, stores it, copies the images onto your own disk, and serves them at a URL you choose — with SEO tags and article schema included.') }}
                                </p>

                                <ol class="mt-4 space-y-3 text-xs text-slate-600 dark:text-slate-300">
                                    <li>
                                        <span class="font-bold text-slate-900 dark:text-slate-100">{{ __('1. Install it') }}</span>
                                        <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 font-mono text-[11px] leading-5 text-slate-100">composer require serfix/content-ai-laravel
php artisan content-ai:install
php artisan migrate
php artisan storage:link</pre>
                                    </li>
                                    <li>
                                        <span class="font-bold text-slate-900 dark:text-slate-100">{{ __('2. Add the signing secret to your .env') }}</span>
                                        <p class="mt-1">{{ __('Copy the secret generated below — it is what proves an article really came from us.') }}</p>
                                        <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 font-mono text-[11px] leading-5 text-slate-100">CONTENT_AI_WEBHOOK_SECRET={{ $whSecret !== '' ? $whSecret : 'paste-the-secret-below' }}
CONTENT_AI_ROUTE_PREFIX=blog</pre>
                                        <p class="mt-1">{{ __('The prefix sets your article URLs — "blog" gives /blog/your-article-link.') }}</p>
                                    </li>
                                    <li>
                                        <span class="font-bold text-slate-900 dark:text-slate-100">{{ __('3. Check it before you connect') }}</span>
                                        <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 font-mono text-[11px] leading-5 text-slate-100">php artisan content-ai:verify</pre>
                                        <p class="mt-1">{{ __('This sends a signed test delivery to your own site, so a wrong secret or a blocked route shows up now rather than on your first article.') }}</p>
                                    </li>
                                    <li>
                                        <span class="font-bold text-slate-900 dark:text-slate-100">{{ __('4. Design it your way (optional)') }}</span>
                                        <p class="mt-1">{{ __('Drop these into your own layout to keep your existing design:') }}</p>
                                        {{-- Verbatim below: these braces are EXAMPLE Blade for the
                                             customer's own template. Without the guard the compiler
                                             would evaluate them here instead of printing them. --}}
                                        @verbatim
                                        <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 font-mono text-[11px] leading-5 text-slate-100">&lt;head&gt; {!! $serfix_head !!} &lt;/head&gt;
&lt;body&gt; {!! $serfix_body !!}
       {!! $serfix_body_below !!} &lt;/body&gt;</pre>
                                        @endverbatim
                                    </li>
                                </ol>

                                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Then fill in the two fields below and connect. Your endpoint must be reachable over https.') }}
                                </p>
                            </div>
                        @else
                            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ __("We'll POST each article as JSON to your endpoint, signed with your secret (X-Serfix-Signature, HMAC-SHA256). Reply 2xx to accept, and return {\"url\": \"...\"} with the article's public address — it powers the live-page link, Google indexing, rank tracking and social auto-share.") }}</p>
                        @endif
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Endpoint URL') }}</label>
                                <input wire:model="whEndpoint" type="text" placeholder="{{ $isLaravel ? $this->suggestedLaravelEndpoint() : 'https://your-site.com/serfix-content' }}"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                @error('whEndpoint') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Signing secret') }}</label>
                                {{-- Generated, and shown in the clear on purpose: it has to be copied
                                     into the receiving site, and we never display it again after save. --}}
                                <div class="mt-1 flex items-center gap-2">
                                    <input wire:model="whSecret" type="text" autocomplete="off" spellcheck="false"
                                        class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-mono text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                    <button type="button" wire:click="regenerateSecret" wire:loading.attr="disabled"
                                        class="shrink-0 rounded-xl border border-slate-300 px-3 py-2.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                        {{ __('Regenerate') }}
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Copy this into your site as CONTENT_AI_WEBHOOK_SECRET. It is the only thing proving an article really came from us — keep it secret, and never reuse it across sites.') }}
                                </p>
                                @error('whSecret') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 flex items-center justify-end gap-2">
                        <button type="button" wire:click="$set('showConnect', false)" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="connect" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                            <span wire:loading.remove wire:target="connect">{{ __('Verify & connect') }}</span>
                            <span wire:loading wire:target="connect">{{ __('Checking…') }}</span>
                        </button>
                    </div>
                    @endif {{-- pendingTarget --}}
                </div>
            @endif
        </div>
    @endif
</div>
