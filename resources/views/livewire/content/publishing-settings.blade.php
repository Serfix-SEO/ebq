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
                                    {{ $integration->platform === \App\Models\ContentIntegration::PLATFORM_WEBHOOK ? __('Custom (webhook)') : __('WordPress') }}
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
                    <div class="flex gap-2">
                        <button type="button" wire:click="$set('platform', '{{ \App\Models\ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD }}')"
                            class="rounded-xl px-4 py-2 text-sm font-bold {{ $platform !== \App\Models\ContentIntegration::PLATFORM_WEBHOOK ? 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                            {{ __('WordPress') }}
                        </button>
                        <button type="button" wire:click="$set('platform', '{{ \App\Models\ContentIntegration::PLATFORM_WEBHOOK }}')"
                            class="rounded-xl px-4 py-2 text-sm font-bold {{ $platform === \App\Models\ContentIntegration::PLATFORM_WEBHOOK ? 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                            {{ __('Custom (webhook)') }}
                        </button>
                    </div>

                    @error('connect') <p class="mt-3 rounded-xl bg-error/10 px-4 py-3 text-sm font-medium text-error">{{ $message }}</p> @enderror

                    @if ($platform !== \App\Models\ContentIntegration::PLATFORM_WEBHOOK)
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
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ __("We'll POST each article as JSON to your endpoint, signed with your secret (X-Serfix-Signature, HMAC-SHA256). Reply 2xx to accept; optionally return {\"url\": \"...\"} so we can link the live page.") }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Endpoint URL') }}</label>
                                <input wire:model="whEndpoint" type="text" placeholder="https://your-site.com/serfix-content"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                @error('whEndpoint') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Signing secret (min 16 characters)') }}</label>
                                <input wire:model="whSecret" type="password" autocomplete="new-password"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
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
