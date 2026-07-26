<x-layouts.guest>
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('Reset your password') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Choose a new password for your account.') }}</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}" />

        <div>
            <label for="email" class="mb-1.5 block text-xs font-medium text-slate-700">{{ __('Email address') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username"
                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            @error('email')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="mb-1.5 block text-xs font-medium text-slate-700">{{ __('New password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            @error('password')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="mb-1.5 block text-xs font-medium text-slate-700">{{ __('Confirm new password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition placeholder:text-slate-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            @error('password_confirmation')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
            class="flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            {{ __('Reset password') }}
        </button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-600">
        <a href="{{ route('login') }}" class="font-semibold text-slate-900 underline-offset-2 hover:underline">{{ __('Back to sign in') }}</a>
    </p>
</x-layouts.guest>
