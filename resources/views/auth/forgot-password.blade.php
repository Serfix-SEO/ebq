<x-layouts.guest>
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('Forgot your password?') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Enter your email and we\'ll send you a link to reset it.') }}</p>
    </div>

    @if (session('status'))
        <div class="mt-6 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="email" class="mb-1.5 block text-xs font-medium text-slate-700">{{ __('Email address') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            @error('email')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
            class="flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            {{ __('Email password reset link') }}
        </button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-600">
        <a href="{{ route('login') }}" class="font-semibold text-slate-900 underline-offset-2 hover:underline">{{ __('Back to sign in') }}</a>
    </p>
</x-layouts.guest>
