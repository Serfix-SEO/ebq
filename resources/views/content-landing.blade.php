@php
    $cfg = \App\Support\ContentAutopilotConfig::class;
    $recaptcha = \App\Support\Recaptcha::isEnabled();
@endphp
<x-marketing.page
    title="Content Autopilot — SEO articles written & published for you — Serfix"
    description="Content Autopilot researches, writes, optimizes, illustrates and publishes an expert SEO article for your site — on autopilot. 5-day free trial, no card."
    active="content"
>
    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section id="start" class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-orange-50/70 via-white to-white">
        {{-- decorative glows --}}
        <div class="pointer-events-none absolute -top-24 start-1/2 h-72 w-[36rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-orange-300 to-amber-200 opacity-40 blur-3xl"></div>
        <div class="mx-auto max-w-4xl px-6 py-20 text-center lg:px-8 lg:py-28">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-white/70 px-3.5 py-1 text-xs font-bold uppercase tracking-[0.15em] text-orange-600 shadow-sm backdrop-blur">
                <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>{{ __('Content Autopilot') }}
            </span>
            <h1 class="mx-auto mt-6 max-w-3xl text-balance text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                {{ __('Expert SEO articles,') }} <span class="bg-gradient-to-r from-orange-500 to-orange-600 bg-clip-text text-transparent">{{ __('written & published for you') }}</span>
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-balance text-lg leading-8 text-slate-600">
                {{ __('Enter your website and we research your niche, write genuinely useful articles, optimize them for search, add images, and publish on your schedule — you review and approve everything.') }}
            </p>

            {{-- Domain capture --}}
            <form method="POST" action="{{ route('content.onboarding.begin') }}" class="mx-auto mt-9 max-w-xl">
                @csrf
                <div class="flex flex-col gap-2.5 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/5 sm:flex-row sm:items-center sm:rounded-full sm:p-2">
                    <div class="flex flex-1 items-center gap-2.5 ps-3">
                        <svg class="h-5 w-5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8" /></svg>
                        <input type="text" name="domain" value="{{ old('domain') }}" placeholder="yourwebsite.com" aria-label="{{ __('Your website') }}"
                            class="w-full border-0 bg-transparent py-3 text-[15px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" />
                    </div>
                    <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                        {{ __('Get started') }}
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </button>
                </div>
                @error('domain') <p class="mt-2.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                @error('g-recaptcha-response') <p class="mt-2.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                @if ($recaptcha)
                    <div class="mt-4 flex justify-center"><div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div></div>
                @endif
            </form>

            <div class="mx-auto mt-5 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-slate-500">
                @foreach ([
                    __(':days-day free trial', ['days' => $cfg::trialDays()]),
                    __(':n free articles', ['n' => $cfg::trialArticles()]),
                    __('No card required'),
                    __('You approve everything'),
                ] as $point)
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="h-4 w-4 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>{{ $point }}
                    </span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Everything it does (features) ─────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Everything a content team does — on autopilot') }}</h2>
                <p class="mt-4 text-lg leading-8 text-slate-600">{{ __('From the first idea to a published, optimized article. You stay in control and approve every step.') }}</p>
            </div>

            <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @php
                    $features = [
                        ['M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', __('Understands your business'), __('We read your website and market to learn exactly what you offer and who you serve.')],
                        ['M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z', __('Finds the right topics'), __('Real searches your customers already make become a content calendar that keeps filling itself.')],
                        ['M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z', __('Writes genuinely useful articles'), __('Long-form articles built to actually help your readers — never thin, generic filler.')],
                        ['M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z', __('Reads like a human'), __('Every article is polished to sound natural and on-brand — not robotic.')],
                        ['M3.75 3v11.25A2.25 2.25 0 006 16.5h12M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6', __('Built-in technical SEO'), __('Titles, structure, internal links, keyword usage and readability are checked before anything publishes.')],
                        ['M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M18 6h.008v.008H18V6zm2.25 12A2.25 2.25 0 0018 20.25H6A2.25 2.25 0 003.75 18V6A2.25 2.25 0 016 3.75h12A2.25 2.25 0 0120.25 6v12z', __('Original images included'), __('Custom illustrations are generated and placed in every article — no stock photos to hunt down.')],
                        ['M3 8.25V18a2.25 2.25 0 002.25 2.25h13.5A2.25 2.25 0 0021 18V8.25m-18 0V6a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 6v2.25m-18 0h18M8.25 3v3m7.5-3v3', __('Publishes to your site'), __('Connect once and approved articles publish automatically on the schedule you set.')],
                        ['M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.63 8.4m5.96 5.97a14.926 14.926 0 01-5.841 2.58m-.119-8.55a6 6 0 00-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 00-2.58 5.84m2.699 2.7c-.103.021-.207.041-.312.06a15.09 15.09 0 01-2.448-2.448 14.9 14.9 0 01.06-.312m-2.24 2.39a4.493 4.493 0 00-1.757 4.306 4.493 4.493 0 004.306-1.758M16.5 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z', __('Knows your competition'), __('See who ranks for your topics so your articles are built to actually compete.')],
                        ['M4.5 12.75l6 6 9-13.5', __('You approve everything'), __('Nothing goes live without your review. Edit any word, reschedule, or skip — anytime.')],
                    ];
                @endphp
                @foreach ($features as [$icon, $title, $desc])
                    <div class="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-lg hover:shadow-orange-600/5">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
                        </div>
                        <h3 class="mt-4 text-base font-bold text-slate-900">{{ $title }}</h3>
                        <p class="mt-1.5 text-sm leading-6 text-slate-600">{{ $desc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── How it works ──────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-6 py-20 lg:px-8">
            <h2 class="text-center text-3xl font-extrabold tracking-tight text-slate-900">{{ __('How it works') }}</h2>
            <p class="mx-auto mt-3 max-w-xl text-center text-slate-600">{{ __('Set up once in a few minutes. Then it runs itself.') }}</p>
            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    [__('Add your website'), __('Enter your domain — we study your site and audience to learn what you sell and who you serve.')],
                    [__('Get your calendar'), __('Real questions and keywords your customers search for become a ready-to-go content plan.')],
                    [__('Articles get written'), __('Each one is written, optimized for SEO, illustrated, and made to read naturally.')],
                    [__('Review & publish'), __('Approve what you like and it publishes to your site on schedule — the calendar keeps refilling.')],
                ] as $i => $step)
                    <div class="relative rounded-2xl border border-slate-200 bg-white p-6">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-100 text-base font-extrabold text-orange-700">{{ $i + 1 }}</div>
                        <h3 class="mt-4 text-base font-bold text-slate-900">{{ $step[0] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $step[1] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Pricing ───────────────────────────────────────────── --}}
    <section class="bg-white">
        <div class="mx-auto max-w-4xl px-6 py-20 lg:px-8">
            <div class="text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('Simple pricing') }}</h2>
                <p class="mt-3 text-slate-600">{{ __('Start free for :days days — no card. Then, for one website:', ['days' => $cfg::trialDays()]) }}</p>
            </div>
            <div class="mt-12 grid gap-6 sm:grid-cols-2">
                <div class="relative overflow-hidden rounded-3xl border-2 border-orange-300 bg-gradient-to-b from-orange-50 to-white p-8 text-center shadow-lg shadow-orange-600/5">
                    <div class="inline-flex rounded-full bg-orange-600 px-3 py-1 text-xs font-bold uppercase tracking-wide text-white">{{ __('$:p first month', ['p' => $cfg::displayPrice('first_month')]) }}</div>
                    <div class="mt-5 text-5xl font-extrabold tracking-tight text-slate-900">${{ $cfg::displayPrice('monthly') }}<span class="text-lg font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="mt-1 text-sm text-slate-500">{{ __('Billed monthly') }}</div>
                </div>
                <div class="rounded-3xl border border-slate-200 bg-white p-8 text-center">
                    <div class="inline-flex rounded-full bg-success/15 px-3 py-1 text-xs font-bold uppercase tracking-wide text-success">{{ __('Best value') }}</div>
                    <div class="mt-5 text-5xl font-extrabold tracking-tight text-slate-900">${{ $cfg::displayPrice('annual') }}<span class="text-lg font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="mt-1 text-sm text-slate-500">{{ __('Billed yearly') }}</div>
                </div>
            </div>
            <p class="mt-6 text-center text-sm text-slate-500">
                {{ __('Up to :n articles per website each month. Each additional website: $:m/mo (or $:a/mo billed yearly).', ['n' => $cfg::monthlyArticlesPerWebsite(), 'm' => $cfg::displayPrice('addon_monthly'), 'a' => $cfg::displayPrice('addon_annual')]) }}
            </p>
        </div>
    </section>

    {{-- ── Closing CTA ───────────────────────────────────────── --}}
    <section class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-3xl px-6 py-20 text-center lg:px-8">
            <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Ready to put your content on autopilot?') }}</h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-slate-600">{{ __('See your first articles and content calendar before you pay a cent.') }}</p>
            <a href="#start" class="mt-8 inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-4 text-base font-bold text-white shadow-lg shadow-orange-600/30 hover:brightness-110">
                {{ __('Get started') }}
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5L12 3m0 0l7.5 7.5M12 3v18" /></svg>
            </a>
            <p class="mt-4 text-sm text-slate-500">{{ __(':days-day free trial · no card required', ['days' => $cfg::trialDays()]) }}</p>
        </div>
    </section>

    @if ($recaptcha)
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif
</x-marketing.page>
