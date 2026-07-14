<x-marketing.page :title="($branding->company_name ?? 'Serfix').' — Backlink report · '.$domain" robots="noindex, nofollow">
    <section class="bg-slate-50">
        <div class="mx-auto max-w-5xl px-6 py-10 lg:px-8">
            <div class="relative">
                <div class="pointer-events-none select-none blur-[6px]" aria-hidden="true">
                    @include('reports.web-body', ['payload' => $payload, 'branding' => $branding])
                </div>
                <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/30 p-4">
                    <div class="my-auto w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-slate-200">
                        @php $activeForm = old('_form', 'signup'); @endphp
                        <div class="mb-1 text-center text-xs font-semibold uppercase tracking-wide text-orange-600">Your report is ready</div>
                        <h3 class="mb-4 text-center text-lg font-bold text-slate-900">{{ $domain }}</h3>

                        @if ($errors->any())
                            <div class="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-center text-xs font-medium text-rose-700 ring-1 ring-rose-100">{{ $errors->first() }}</div>
                        @endif

                        {{-- Sign up --}}
                        <div id="az-signup" class="{{ $activeForm === 'signin' ? 'hidden' : '' }}">
                            <p class="mb-4 text-center text-sm text-slate-600">Create your free account to unlock the full backlink &amp; authority report.</p>
                            <form method="POST" action="{{ route('register') }}" class="space-y-3">
                                @csrf
                                <input type="hidden" name="_form" value="signup">
                                <input type="text" name="name" required placeholder="Full name" autocomplete="name" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                <input type="email" name="email" required placeholder="Email address" autocomplete="email" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                @include('partials.phone-input')
                                <input type="password" name="password" required placeholder="Password" autocomplete="new-password" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                <input type="password" name="password_confirmation" required placeholder="Confirm password" autocomplete="new-password" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                @if (\App\Support\Recaptcha::isEnabled())
                                    <div class="flex justify-center"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
                                @endif
                                <button type="submit" class="w-full rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-700">Create free account &amp; view report</button>
                            </form>
                            <p class="mt-4 text-center text-xs text-slate-500">Already have an account?
                                <button type="button" data-az-toggle="signin" class="font-medium text-orange-600 hover:underline">Sign in</button>
                            </p>
                        </div>

                        {{-- Sign in --}}
                        <div id="az-signin" class="{{ $activeForm === 'signin' ? '' : 'hidden' }}">
                            <p class="mb-4 text-center text-sm text-slate-600">Sign in to view your report.</p>
                            <form method="POST" action="{{ route('login') }}" class="space-y-3">
                                @csrf
                                <input type="hidden" name="_form" value="signin">
                                <input type="email" name="email" required placeholder="Email address" autocomplete="email" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                <input type="password" name="password" required placeholder="Password" autocomplete="current-password" class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                                <label class="flex items-center gap-2 text-xs text-slate-500"><input type="checkbox" name="remember" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30"> Remember me</label>
                                @if (\App\Support\Recaptcha::isEnabled())
                                    <div class="flex justify-center"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
                                @endif
                                <button type="submit" class="w-full rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-700">Sign in &amp; view report</button>
                            </form>
                            <p class="mt-4 text-center text-xs text-slate-500">Need an account?
                                <button type="button" data-az-toggle="signup" class="font-medium text-orange-600 hover:underline">Create one</button>
                            </p>
                        </div>
                    </div>
                </div>
                <script>
                    document.querySelectorAll('[data-az-toggle]').forEach(function (b) {
                        b.addEventListener('click', function () {
                            var to = b.getAttribute('data-az-toggle');
                            document.getElementById('az-signup').classList.toggle('hidden', to !== 'signup');
                            document.getElementById('az-signin').classList.toggle('hidden', to !== 'signin');
                        });
                    });
                </script>
            </div>
            @if (\App\Support\Recaptcha::isEnabled())
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            @endif
        </div>
    </section>
</x-marketing.page>
