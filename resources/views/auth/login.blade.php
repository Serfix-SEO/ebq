<x-layouts.guest>
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('Welcome back') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Sign in to your Serfix account') }}</p>
    </div>

    @error('auth')
        <div class="mt-6 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
            <span>{{ $message }}</span>
        </div>
    @enderror

    @if (session('session_notice'))
        <div class="mt-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
            <span>{{ session('session_notice') }}</span>
        </div>
    @endif

    <a href="{{ route('google.sso.redirect', ['intent' => 'login']) }}"
        class="mt-8 inline-flex w-full items-center justify-center gap-2.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
        <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.2-.9 2.2-1.9 2.9l3 2.3c1.7-1.6 2.7-3.9 2.7-6.7 0-.7-.1-1.4-.2-2H12z"/>
            <path fill="#34A853" d="M12 21c2.4 0 4.5-.8 6-2.2l-3-2.3c-.8.6-1.9 1-3.1 1-2.4 0-4.4-1.6-5.1-3.8l-3.1 2.4C5.2 19 8.3 21 12 21z"/>
            <path fill="#4A90E2" d="M6.9 13.7c-.2-.6-.3-1.1-.3-1.7s.1-1.2.3-1.7l-3.1-2.4C3.3 8.9 3 10.4 3 12s.3 3.1.8 4.1l3.1-2.4z"/>
            <path fill="#FBBC05" d="M12 6.5c1.3 0 2.5.5 3.5 1.4l2.6-2.6C16.5 3.8 14.4 3 12 3 8.3 3 5.2 5 3.8 7.9l3.1 2.4c.7-2.2 2.7-3.8 5.1-3.8z"/>
        </svg>
        {{ __('Continue with Google') }}
    </a>

    <div class="mt-6 flex items-center gap-3 text-xs uppercase tracking-[0.16em] text-slate-400">
        <span class="h-px flex-1 bg-slate-200"></span>
        <span>{{ __('or continue with email') }}</span>
        <span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="email" class="mb-1.5 block text-xs font-medium text-slate-700">{{ __('Email address') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            @error('email')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="mb-1.5 block text-xs font-medium text-slate-700">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            @error('password')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center">
            <input id="remember" name="remember" type="checkbox" value="1"
                class="h-4 w-4 rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
            <label for="remember" class="ml-2 text-sm text-slate-600">{{ __('Remember me') }}</label>
        </div>

        @if (\App\Support\Recaptcha::isEnabled())
            <div>
                <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                @error('g-recaptcha-response')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <button type="submit"
            class="flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            {{ __('Sign in') }}
        </button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-600">
        {{ __('Don\'t have an account?') }}
        <a href="{{ route('register') }}" class="font-semibold text-slate-900 underline-offset-2 hover:underline">{{ __('Create one') }}</a>
    </p>
</x-layouts.guest>
