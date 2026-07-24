{{-- Reusable signup / signin modal (phone country dropdown + inline toggle).
     Used behind the blurred teaser on Site Explorer and every public tool.
     Params: $redirect (local URL to return to after auth), $title, $subtitle. --}}
@php
    $redirect = $redirect ?? '';
    $title = $title ?? 'Your result is ready';
    $subtitle = $subtitle ?? 'Create your free account to unlock the full result.';
    $activeForm = old('_form', 'signup');
@endphp
<div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-slate-200">
    <div class="mb-1 text-center text-xs font-semibold uppercase tracking-wide text-orange-600">{{ $title }}</div>
    @isset($heading)<h3 class="mb-4 text-center text-lg font-bold text-slate-900">{{ $heading }}</h3>@endisset

    @if (isset($errors) && $errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-center text-xs font-medium text-rose-700 ring-1 ring-rose-100">{{ $errors->first() }}</div>
    @endif

    {{-- Google SSO — identity-only (minimum) scopes; find-or-create + sign in.
         Serves both panels (Google handles new + returning). Carries $redirect
         so the visitor lands back on the result they were viewing. --}}
    <a href="{{ route('google.sso.redirect', ['intent' => 'register', 'redirect' => $redirect]) }}"
        class="inline-flex w-full items-center justify-center gap-2.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
        <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.2-.9 2.2-1.9 2.9l3 2.3c1.7-1.6 2.7-3.9 2.7-6.7 0-.7-.1-1.4-.2-2H12z"/>
            <path fill="#34A853" d="M12 21c2.4 0 4.5-.8 6-2.2l-3-2.3c-.8.6-1.9 1-3.1 1-2.4 0-4.4-1.6-5.1-3.8l-3.1 2.4C5.2 19 8.3 21 12 21z"/>
            <path fill="#4A90E2" d="M6.9 13.7c-.2-.6-.3-1.1-.3-1.7s.1-1.2.3-1.7l-3.1-2.4C3.3 8.9 3 10.4 3 12s.3 3.1.8 4.1l3.1-2.4z"/>
            <path fill="#FBBC05" d="M12 6.5c1.3 0 2.5.5 3.5 1.4l2.6-2.6C16.5 3.8 14.4 3 12 3 8.3 3 5.2 5 3.8 7.9l3.1 2.4c.7-2.2 2.7-3.8 5.1-3.8z"/>
        </svg>
        {{ __('Continue with Google') }}
    </a>
    <div class="my-4 flex items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-slate-400">
        <span class="h-px flex-1 bg-slate-200"></span>{{ __('or') }}<span class="h-px flex-1 bg-slate-200"></span>
    </div>

    {{-- Sign up --}}
    <div id="am-signup" class="{{ $activeForm === 'signin' ? 'hidden' : '' }}">
        <p class="mb-4 text-center text-sm text-slate-600">{{ $subtitle }}</p>
        <form method="POST" action="{{ route('register') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="_form" value="signup">
            <input type="hidden" name="redirect" value="{{ $redirect }}">
            <input type="text" name="name" required placeholder="Full name" autocomplete="name" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            <input type="email" name="email" required placeholder="Email address" autocomplete="email" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            @include('partials.phone-input')
            <input type="password" name="password" required placeholder="Password" autocomplete="new-password" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            <input type="password" name="password_confirmation" required placeholder="Confirm password" autocomplete="new-password" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            @if (\App\Support\Recaptcha::isEnabled())
                <div class="flex justify-center"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
            @endif
            <button type="submit" class="w-full rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-700">Create free account &amp; view result</button>
        </form>
        <p class="mt-4 text-center text-xs text-slate-500">Already have an account?
            <button type="button" data-am-toggle="signin" class="font-medium text-orange-600 hover:underline">Sign in</button>
        </p>
    </div>

    {{-- Sign in --}}
    <div id="am-signin" class="{{ $activeForm === 'signin' ? '' : 'hidden' }}">
        <p class="mb-4 text-center text-sm text-slate-600">Sign in to view your result.</p>
        <form method="POST" action="{{ route('login') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="_form" value="signin">
            <input type="hidden" name="redirect" value="{{ $redirect }}">
            <input type="email" name="email" required placeholder="Email address" autocomplete="email" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            <input type="password" name="password" required placeholder="Password" autocomplete="current-password" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
            <label class="flex items-center gap-2 text-xs text-slate-500"><input type="checkbox" name="remember" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30"> Remember me</label>
            @if (\App\Support\Recaptcha::isEnabled())
                <div class="flex justify-center"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
            @endif
            <button type="submit" class="w-full rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-700">Sign in &amp; view result</button>
        </form>
        <p class="mt-4 text-center text-xs text-slate-500">Need an account?
            <button type="button" data-am-toggle="signup" class="font-medium text-orange-600 hover:underline">Create one</button>
        </p>
    </div>
</div>
<script>
    document.querySelectorAll('[data-am-toggle]').forEach(function (b) {
        b.addEventListener('click', function () {
            var to = b.getAttribute('data-am-toggle');
            document.getElementById('am-signup').classList.toggle('hidden', to !== 'signup');
            document.getElementById('am-signin').classList.toggle('hidden', to !== 'signin');
        });
    });
</script>
