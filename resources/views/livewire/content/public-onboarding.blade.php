@php $recaptcha = \App\Support\Recaptcha::isEnabled(); @endphp
<div class="mx-auto max-w-xl px-6 py-12">
    {{-- Progress --}}
    <div class="mb-8 flex items-center justify-center gap-2">
        @foreach ([1 => __('Website'), 2 => __('About you'), 3 => __('Create account')] as $n => $label)
            <div class="flex items-center gap-2">
                <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold {{ $step >= $n ? 'bg-orange-600 text-white' : 'bg-slate-200 text-slate-500 dark:bg-slate-700' }}">{{ $n }}</span>
                <span class="hidden text-xs font-medium sm:inline {{ $step >= $n ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400' }}">{{ $label }}</span>
                @if ($n < 3)<span class="h-px w-6 bg-slate-200 dark:bg-slate-700"></span>@endif
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        {{-- ── Step 1: website ──────────────────────────────────── --}}
        @if ($step === 1)
            <h1 class="text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('What website is this for?') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('We will write and publish SEO articles for this site. It becomes your website when you finish signing up.') }}</p>
            <form wire:submit="startWithDomain" class="mt-6 space-y-4">
                <div>
                    <input type="text" wire:model="domain" placeholder="yourwebsite.com" autofocus
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    @error('domain') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
                </div>
                @if ($recaptcha)
                    <div wire:ignore class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-callback="onContentCaptcha"></div>
                @endif
                <button type="submit" wire:loading.attr="disabled"
                    class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    <span wire:loading.remove wire:target="startWithDomain">{{ __('Continue') }}</span>
                    <span wire:loading wire:target="startWithDomain">{{ __('Setting up…') }}</span>
                </button>
            </form>

        {{-- ── Step 2: profile ──────────────────────────────────── --}}
        @elseif ($step === 2)
            <h1 class="text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Tell us about your business') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('So we write about what you actually offer. You can refine this later.') }}</p>
            <form wire:submit="toDetails" class="mt-6 space-y-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('What does :domain do?', ['domain' => $domain]) }}</label>
                    <textarea wire:model="businessDescription" rows="4" placeholder="{{ __('We sell…') }}"
                        class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                    @error('businessDescription') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold text-success">{{ __('You sell / offer') }}</label>
                        <div class="mt-1 flex gap-2">
                            <input type="text" wire:model="newSell" wire:keydown.enter.prevent="addSell" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            <button type="button" wire:click="addSell" class="rounded-lg bg-success px-3 text-white">+</button>
                        </div>
                        <div class="mt-2 space-y-1">
                            @foreach ($sellItems as $i => $item)
                                <div class="flex items-center justify-between rounded-lg bg-slate-50 px-2.5 py-1 text-xs dark:bg-slate-800">{{ $item }}<button type="button" wire:click="removeSell({{ $i }})" class="text-slate-400 hover:text-error">&times;</button></div>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500">{{ __('You do NOT offer') }}</label>
                        <div class="mt-1 flex gap-2">
                            <input type="text" wire:model="newDont" wire:keydown.enter.prevent="addDont" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            <button type="button" wire:click="addDont" class="rounded-lg bg-slate-500 px-3 text-white">+</button>
                        </div>
                        <div class="mt-2 space-y-1">
                            @foreach ($dontSellItems as $i => $item)
                                <div class="flex items-center justify-between rounded-lg bg-slate-50 px-2.5 py-1 text-xs dark:bg-slate-800">{{ $item }}<button type="button" wire:click="removeDont({{ $i }})" class="text-slate-400 hover:text-error">&times;</button></div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">{{ __('Continue') }}</button>
            </form>

        {{-- ── Step 3: account ──────────────────────────────────── --}}
        @else
            <h1 class="text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Create your account') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Start your free trial — no card required. We will email you a link to set a password.') }}</p>
            <form wire:submit="createAccount" class="mt-6 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Full name') }}</label>
                    <input type="text" wire:model="name" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    @error('name') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Email') }}</label>
                    <input type="email" wire:model="email" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    @error('email') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }} <a href="{{ route('login') }}" class="underline">{{ __('Log in') }}</a></p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Phone') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                    <input type="text" wire:model="phone" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Password') }}</label>
                    <input type="password" wire:model="password" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    @error('password') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Confirm password') }}</label>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                </div>
                @if ($recaptcha)
                    <div wire:ignore class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-callback="onContentCaptcha"></div>
                @endif
                <button type="submit" wire:loading.attr="disabled" class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    <span wire:loading.remove wire:target="createAccount">{{ __('Start free trial') }}</span>
                    <span wire:loading wire:target="createAccount">{{ __('Creating your account…') }}</span>
                </button>
            </form>
        @endif
    </div>

    @if ($recaptcha)
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        <script>window.onContentCaptcha = (t) => { @this.set('recaptchaToken', t); };</script>
    @endif
</div>
