<div>
    @if ($hasWebsite && $plan !== null)
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
                                    @if ($integration->platform !== \App\Models\ContentIntegration::PLATFORM_WEBHOOK)
                                        {{ __('WordPress') }}
                                    {{-- Laravel is a webhook under the hood; the flavour recorded at
                                         connect time is what lets us name it properly here. --}}
                                    @elseif (($integration->config['flavor'] ?? null) === \App\Livewire\Content\PublishingSettings::FLAVOR_LARAVEL)
                                        {{ __('Laravel') }}
                                    @else
                                        {{ __('Custom (webhook)') }}
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
                        $isWordPress = ! $isLaravel && ! $isWebhook;
                        $tabOn = 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300';
                        $tabOff = 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800';
                    @endphp
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="selectPlatform('{{ \App\Models\ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD }}')"
                            class="rounded-xl px-4 py-2 text-sm font-bold {{ $isWordPress ? $tabOn : $tabOff }}">
                            {{ __('WordPress') }}
                        </button>
                        <button type="button" wire:click="selectPlatform('{{ \App\Livewire\Content\PublishingSettings::FLAVOR_LARAVEL }}')"
                            class="rounded-xl px-4 py-2 text-sm font-bold {{ $isLaravel ? $tabOn : $tabOff }}">
                            {{ __('Laravel') }}
                        </button>
                        <button type="button" wire:click="selectPlatform('{{ \App\Models\ContentIntegration::PLATFORM_WEBHOOK }}')"
                            class="rounded-xl px-4 py-2 text-sm font-bold {{ $isWebhook ? $tabOn : $tabOff }}">
                            {{ __('Custom (webhook)') }}
                        </button>
                    </div>

                    @error('connect') <p class="mt-3 rounded-xl bg-error/10 px-4 py-3 text-sm font-medium text-error">{{ $message }}</p> @enderror

                    @if ($isWordPress)
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ __('In WordPress go to Users → Profile → Application Passwords, create one named "Serfix", and paste it here. The account needs to be an Author or above.') }}</p>
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
                            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ __("We'll POST each article as JSON to your endpoint, signed with your secret (X-Serfix-Signature, HMAC-SHA256). Reply 2xx to accept; optionally return {\"url\": \"...\"} so we can link the live page.") }}</p>
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
                </div>
            @endif
        </div>
    @endif
</div>
