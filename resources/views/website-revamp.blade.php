<x-marketing.page
    title="Website Revamp for Business Sites | More Leads & Sales | Serfix"
    description="We take tired business websites and rebuild them into fast, modern ones that make it easy to contact you or buy. Built for SEO and ad traffic, so more of your visitors turn into leads and customers."
    active="revamp"
>
    {{-- Page-specific structured data: the service + the FAQ. --}}
    <x-slot:schema>
        @php
            $revampJsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

            $serviceSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                'name' => 'Business Website Revamp for Leads & Sales',
                'serviceType' => 'Website redesign, conversion optimization, and SEO',
                'provider' => ['@type' => 'Organization', 'name' => 'Serfix', 'url' => route('landing')],
                'areaServed' => 'Worldwide',
                'audience' => ['@type' => 'Audience', 'audienceType' => 'Businesses'],
                'url' => url()->current(),
                'description' => 'Done-for-you revamp for business websites: a modern, fast redesign with clear paths to contact and purchase, optimized for SEO and paid ad campaigns to turn visitors into leads and sales.',
            ];

            $revampFaqs = [
                [__('We run ads but barely get leads. Can a revamp fix that?'), __('Usually it can. With most campaigns the money gets wasted on the page, not the ad. If someone lands and can’t tell what you do or how to reach you in a couple of seconds, they’re gone. We give every page one clear message, buttons you can’t miss, and a contact or checkout flow that takes seconds. The traffic you’re already paying for starts to convert.')],
                [__('Is this a redesign or an SEO service?'), __('Both, really. We modernize how the site looks and works, fix the SEO so Google sends you more of the right people, and shape each page to turn those visits into enquiries. A sharp-looking site earns trust, SEO brings the traffic, and the conversion work brings the leads. You need all three.')],
                [__('Will I lose my Google rankings?'), __('No. We handle the move carefully. Your URLs stay the same or get redirected properly, the structure and metadata carry over, and we check indexing before and after launch. You get a modern site and keep the search visibility you’ve already earned.')],
                [__('How long does it take, and what do I need to do?'), __('Most projects take two to four weeks. After a quick call we handle the design, build, SEO and conversion work, and you just review and sign off. Your brand stays yours. We only rebuild the parts that bring in and convert traffic.')],
                [__('How will I know it worked?'), __('We take a snapshot before we start, then show you what changed: more enquiries and sales, better conversion on your ads, higher rankings and more organic traffic. It all lives in a Serfix workspace you keep after we’re done.')],
            ];
            $faqSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($q) => [
                    '@type' => 'Question',
                    'name' => $q[0],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
                ], $revampFaqs),
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($serviceSchema, $revampJsonFlags) !!}</script>
        <script type="application/ld+json">{!! json_encode($faqSchema, $revampJsonFlags) !!}</script>
    </x-slot:schema>

    @php
        // jez@ebq.io's "Website Revamp" booking link on marketing.ebq.io.
        $bookingUrl = 'https://marketing.ebq.io/book/website-revamp-f31m9';
    @endphp

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden border-b border-slate-200">
        {{-- Before/after example sites in the background --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
            {{-- subtle tinted halves --}}
            <div class="absolute inset-y-0 left-0 w-1/2 bg-slate-100/70"></div>
            <div class="absolute inset-y-0 right-0 w-1/2 bg-gradient-to-br from-orange-50 to-emerald-50/60"></div>

            {{-- left: realistic dated site --}}
            <div class="absolute top-12 hidden w-[27rem] -rotate-2 opacity-95 md:-left-14 md:block lg:-left-8 lg:w-[31rem]">
                @include('partials.revamp-before-site')
            </div>

            {{-- right: modern site --}}
            <div class="absolute top-12 hidden w-[27rem] rotate-2 opacity-95 md:-right-14 md:block lg:-right-8 lg:w-[31rem]">
                @include('partials.revamp-after-site')
            </div>

            {{-- blurry cloud behind the hero text so the side mockups never hurt readability --}}
            <div class="absolute left-1/2 top-[40%] h-[22rem] w-[54rem] max-w-[92%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white/85 blur-3xl"></div>
            <div class="absolute left-1/2 top-[30%] h-56 w-[40rem] max-w-[88%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white/90 blur-2xl"></div>
            <div class="absolute left-[40%] top-[48%] h-44 w-[30rem] max-w-[80%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white/80 blur-2xl"></div>
            <div class="absolute left-[60%] top-[44%] h-40 w-[28rem] max-w-[80%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white/80 blur-2xl"></div>
            <div class="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-white to-transparent"></div>
            <div class="absolute inset-x-0 top-0 h-10 bg-gradient-to-b from-white to-transparent"></div>

            {{-- corner labels --}}
            <span class="absolute left-5 top-5 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400/80">{{ __('Before') }}</span>
            <span class="absolute right-5 top-5 text-[11px] font-semibold uppercase tracking-[0.18em] text-orange-500/80">{{ __('After') }}</span>
        </div>
        <div class="relative mx-auto max-w-4xl px-6 pb-20 pt-16 text-center lg:px-8 lg:pb-24 lg:pt-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Website revamp for business') }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                {{ __('A modern website your customers can actually buy from.') }}
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('We take tired business websites and rebuild them into fast, modern ones that are built to convert. Clear messaging, easy ways to get in touch or buy, and SEO that brings the right people in, so your traffic and ad spend finally turn into leads and sales.') }}
            </p>
            <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ $bookingUrl }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Book a free strategy call') }}</a>
                <a href="{{ route('tools.audit') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">{{ __('Get a free site audit') }}</a>
            </div>
            <p class="mt-4 text-xs text-slate-500">{{ __('15-minute call · no obligation · we review your site and where you’re losing leads, live.') }}</p>

            {{-- Mobile-only before/after preview — same mockups as the desktop background, stacked --}}
            <div class="mx-auto mt-10 max-w-sm space-y-5 md:hidden">
                <div>
                    <p class="mb-1.5 text-left text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Before') }}</p>
                    @include('partials.revamp-before-site')
                </div>
                <div>
                    <p class="mb-1.5 text-left text-[10px] font-semibold uppercase tracking-[0.18em] text-orange-500">{{ __('After') }}</p>
                    @include('partials.revamp-after-site')
                </div>
            </div>

            {{-- Anchor pill nav --}}
            <nav aria-label="{{ __('Revamp sections') }}" class="mx-auto mt-12 flex max-w-3xl flex-wrap items-center justify-center gap-2 text-xs font-medium">
                @foreach ([
                    ['#problem', __('The problem')],
                    ['#what', __('What we do')],
                    ['#example', __('Before & after')],
                    ['#process', __('Process')],
                    ['#outcomes', __('Results')],
                    ['#faq', __('FAQ')],
                ] as [$href, $label])
                    <a href="{{ $href }}" class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-600 transition hover:border-slate-300 hover:text-slate-900">{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </section>

    {{-- ── The problem ───────────────────────────────────────── --}}
    <section id="problem" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-rose-600">{{ __('Sound familiar?') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('You’re paying for traffic, then losing it.') }}</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">{{ __('You spend on ads and SEO, the visitors show up, and then nothing happens. Nine times out of ten, the website is the leak.') }}</p>
            </div>
            <div class="mx-auto mt-12 grid max-w-4xl gap-4 sm:grid-cols-3">
                @foreach ([
                    [__('Ads that don’t convert'), __('Your ads send clicks to a page that doesn’t make it obvious what you do or what to do next. People bounce, and the budget goes with them.')],
                    [__('No clear way to contact or buy'), __('Phone numbers buried in the footer, forms nobody can find, a clunky checkout. People who were ready to buy give up before they reach you.')],
                    [__('An outdated, slow site'), __('An old-looking site loses trust, and a slow one loses patience. That hurts most on mobile, which is where most of your visitors already are.')],
                ] as [$t, $d])
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/60 p-6">
                        <h3 class="text-base font-semibold text-slate-900">{{ $t }}</h3>
                        <p class="mt-2 text-[13px] leading-6 text-slate-600">{{ $d }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── What we do ────────────────────────────────────────── --}}
    <section id="what" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('What we do') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('A modern site that turns visitors into customers.') }}</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">{{ __('Four things that work together so the traffic you earn and pay for actually turns into customers.') }}</p>
            </div>
            <div class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-2">
                @foreach ([
                    [__('Modern, credible redesign'), __('A clean, professional design that works on phones first and loads fast. It looks like a business people feel safe buying from.')],
                    [__('Clear paths to contact & buy'), __('Buttons you can’t miss, tap-to-call, short forms, WhatsApp, and a checkout or enquiry flow that takes seconds. A ready customer should never have to go hunting.')],
                    [__('Pages built for leads'), __('We rewrite the words around what your customers actually care about, answer the questions they have, and point them to the next step. That means more form fills, more calls, more sales.')],
                    [__('Found on Google & ad-ready'), __('Solid SEO so the right people find you on Google, and landing pages that turn your paid clicks into enquiries instead of burning the budget.')],
                ] as [$t, $d])
                    <article class="bg-white p-7">
                        <h3 class="text-lg font-semibold text-slate-900">{{ $t }}</h3>
                        <p class="mt-2 text-[14px] leading-7 text-slate-600">{{ $d }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Before / after sketch ─────────────────────────────── --}}
    <section id="example" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Before & after') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('See where the leads were leaking.') }}</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">
                    {{ __('Here’s a real example. A local plumbing firm was running Google Ads, but the old site let visitors slip away before they ever picked up the phone. Same traffic, same budget. This is what changed.') }}
                </p>
            </div>

            <div class="mt-12 grid items-start gap-8 lg:grid-cols-2 lg:gap-10">
                {{-- ── BEFORE ──────────────────────────────────── --}}
                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-rose-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-rose-700 ring-1 ring-rose-100">{{ __('Before') }}</span>
                        <span class="text-[12px] text-slate-500">{{ __('Visitors bounced, leads lost') }}</span>
                    </div>

                    {{-- Fake browser window: realistic dated template --}}
                    <div class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm ring-1 ring-rose-100">
                        {{-- realistic browser chrome --}}
                        <div class="flex items-center gap-2 border-b border-slate-200 bg-gradient-to-b from-slate-100 to-slate-200/80 px-3 py-2">
                            <div class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full bg-rose-400/90"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-400/90"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/90"></span>
                            </div>
                            <div class="ml-1.5 hidden items-center gap-1.5 text-slate-400 sm:flex">
                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                <svg class="h-3 w-3 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </div>
                            <div class="ml-1 flex flex-1 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-500">
                                <svg class="h-2.5 w-2.5 flex-none text-slate-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg>
                                <span class="truncate">www.smithplumbing.co</span>
                                <svg class="ml-auto h-2.5 w-2.5 flex-none text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M3.182 9.348a8.25 8.25 0 0113.803-3.7l3.181 3.181m0 0V4.5m0 4.848h-4.992" /></svg>
                            </div>
                        </div>

                        {{-- dated small-business template --}}
                        <div class="bg-white">
                            {{-- utility / brand bar --}}
                            <div class="flex items-end justify-between border-b border-slate-200 px-4 py-2.5">
                                <div class="leading-tight">
                                    <p class="font-serif text-[12px] font-bold text-sky-900">Smith &amp; Co <span class="font-normal italic text-slate-500">Plumbing</span></p>
                                    <p class="text-[6px] uppercase tracking-wide text-slate-400">Established 1998 · Gas Safe Registered</p>
                                </div>
                                <div class="text-right leading-tight">
                                    <p class="text-[6px] uppercase tracking-wide text-slate-400">{{ __('Call us today') }}</p>
                                    <p class="text-[9px] font-bold text-slate-600">0123 456 789</p>
                                </div>
                            </div>
                            {{-- beveled tab nav --}}
                            <div class="flex bg-gradient-to-b from-sky-700 to-sky-800 text-[7px] font-semibold text-sky-50">
                                @foreach ([__('Home'), __('About'), __('Services'), __('Gallery'), __('Testimonials'), __('Contact')] as $tab)
                                    <span class="border-r border-sky-600/60 px-2.5 py-1.5 {{ $loop->first ? 'bg-sky-900/40' : '' }}">{{ $tab }}</span>
                                @endforeach
                            </div>
                            {{-- stock-photo hero placeholder --}}
                            <div class="relative flex h-20 items-center justify-center overflow-hidden bg-gradient-to-br from-slate-500 via-slate-600 to-slate-700">
                                <svg class="h-6 w-6 text-white/25" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M1.5 6a2.25 2.25 0 012.25-2.25h16.5A2.25 2.25 0 0122.5 6v12a2.25 2.25 0 01-2.25 2.25H3.75A2.25 2.25 0 011.5 18V6zM3 16.06V18c0 .414.336.75.75.75h16.5A.75.75 0 0021 18v-1.94l-2.69-2.689a1.5 1.5 0 00-2.12 0l-.88.879.97.97a.75.75 0 11-1.06 1.06l-5.16-5.159a1.5 1.5 0 00-2.12 0L3 16.061zm10.125-7.81a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0z" clip-rule="evenodd" /></svg>
                                <div class="absolute inset-x-0 bottom-0 bg-black/60 px-3 py-1">
                                    <p class="text-[8px] font-bold tracking-tight text-white">{{ __('Quality Plumbing Services You Can Trust') }}</p>
                                </div>
                            </div>
                            {{-- two-column body --}}
                            <div class="grid grid-cols-3 gap-3 px-4 py-3">
                                <div class="col-span-2">
                                    <p class="text-[8px] font-bold text-sky-900">{{ __('Welcome to our website') }}</p>
                                    <p class="mt-1 text-justify text-[6.5px] leading-[1.6] text-slate-500">
                                        Established in 1998, Smith &amp; Co Plumbing has proudly served the local community for over two decades with a comprehensive range of plumbing solutions for both residential and commercial customers. Our team of fully qualified engineers is committed to the highest standards of workmanship on every job, large or small.
                                    </p>
                                    <p class="mt-1.5 text-justify text-[6.5px] leading-[1.6] text-slate-500">
                                        {{ __('We offer boiler installation, repairs, bathroom fitting, drainage and emergency call-outs. Please feel free to browse our website to learn more about the services we provide.') }}
                                    </p>
                                </div>
                                <div class="col-span-1">
                                    <div class="border border-slate-200 bg-slate-50 p-2">
                                        <p class="text-[7px] font-bold text-slate-600">{{ __('Why Choose Us?') }}</p>
                                        <ul class="mt-1 space-y-0.5 text-[6px] text-slate-500">
                                            <li>• {{ __('Over 25 years\' experience') }}</li>
                                            <li>• {{ __('Fully qualified engineers') }}</li>
                                            <li>• {{ __('Free no-obligation quotes') }}</li>
                                            <li>• {{ __('Covering all of Manchester') }}</li>
                                        </ul>
                                        <p class="mt-1.5 text-[7px] text-sky-700 underline">{{ __('Get a Quote &raquo;') }}</p>
                                    </div>
                                </div>
                            </div>
                            {{-- footer --}}
                            <div class="bg-slate-700 px-4 py-1.5">
                                <p class="text-[6px] text-slate-300">© 2012 Smith &amp; Co Plumbing Ltd · 14 High Street, Manchester · info@smithplumbing.co</p>
                            </div>
                        </div>
                    </div>

                    {{-- callouts --}}
                    <ul class="mt-5 space-y-2.5 text-[13px] text-slate-700">
                        @foreach ([
                            __('No clear headline, so visitors can’t tell what you do at a glance'),
                            __('No buttons anywhere obvious to click or convert'),
                            __('Phone number buried in tiny footer text'),
                            __('A wall of text with no clear offer or next step'),
                            __('Dated, cluttered look that felt untrustworthy on mobile'),
                        ] as $issue)
                            <li class="flex items-start gap-2.5">
                                <svg class="mt-0.5 h-4 w-4 flex-none text-rose-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                <span>{{ $issue }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- ── AFTER ───────────────────────────────────── --}}
                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-emerald-700 ring-1 ring-emerald-100">{{ __('After') }}</span>
                        <span class="text-[12px] text-slate-500">{{ __('Visitors call & convert') }}</span>
                    </div>

                    {{-- Fake browser window: modern, conversion-focused site --}}
                    <div class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-md ring-1 ring-emerald-100">
                        {{-- realistic browser chrome --}}
                        <div class="flex items-center gap-2 border-b border-slate-200 bg-gradient-to-b from-slate-100 to-slate-200/80 px-3 py-2">
                            <div class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full bg-rose-400/90"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-400/90"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/90"></span>
                            </div>
                            <div class="ml-1.5 hidden items-center gap-1.5 text-slate-400 sm:flex">
                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                <svg class="h-3 w-3 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </div>
                            <div class="ml-1 flex flex-1 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-500">
                                <svg class="h-2.5 w-2.5 flex-none text-emerald-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg>
                                <span class="truncate">www.smithplumbing.co</span>
                                <svg class="ml-auto h-2.5 w-2.5 flex-none text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M3.182 9.348a8.25 8.25 0 0113.803-3.7l3.181 3.181m0 0V4.5m0 4.848h-4.992" /></svg>
                            </div>
                        </div>
                        <div class="bg-white">
                            {{-- sticky header with nav + click-to-call --}}
                            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="flex h-4 w-4 items-center justify-center rounded bg-orange-600 text-[8px] font-black leading-none text-white">S</span>
                                    <span class="text-[11px] font-extrabold tracking-tight text-slate-900">Smith&nbsp;&amp;&nbsp;Co<span class="text-orange-600">.</span></span>
                                </div>
                                <div class="hidden items-center gap-2.5 text-[8px] font-medium text-slate-500 sm:flex">
                                    <span>{{ __('Services') }}</span><span>{{ __('Reviews') }}</span><span>{{ __('Areas') }}</span><span>{{ __('Contact') }}</span>
                                </div>
                                <span class="inline-flex items-center gap-1 rounded-md bg-orange-600 px-2.5 py-1 text-[9px] font-semibold text-white shadow-sm">
                                    <svg class="h-2.5 w-2.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 013.5 2h1.148a1.5 1.5 0 011.465 1.175l.716 3.223a1.5 1.5 0 01-1.052 1.767l-.933.267c.41 1.115 1.07 2.13 1.93 2.99s1.875 1.52 2.99 1.93l.267-.933a1.5 1.5 0 011.767-1.052l3.223.716A1.5 1.5 0 0118 15.352V16.5a1.5 1.5 0 01-1.5 1.5H15c-7.18 0-13-5.82-13-13V3.5z" clip-rule="evenodd" /></svg>
                                    {{ __('Call') }} (555) 010-2030
                                </span>
                            </div>
                            {{-- hero --}}
                            <div class="bg-gradient-to-b from-orange-50/70 to-white px-6 pb-8 pt-8 text-center">
                                <span class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-[8px] font-semibold text-emerald-700 ring-1 ring-emerald-100">{{ __('★★★★★ 480+ reviews · Licensed & insured') }}</span>
                                <p class="mt-4 text-[18px] font-extrabold leading-tight tracking-tight text-slate-900">{{ __('Emergency Plumber') }}<br>{{ __('in Manchester, Fixed Today') }}</p>
                                <p class="mx-auto mt-3 max-w-[15rem] text-[9px] leading-relaxed text-slate-600">{{ __('No call-out fee. Upfront pricing. A qualified engineer at your door within 60 minutes.') }}</p>
                                <div class="mt-5 flex items-center justify-center gap-2.5">
                                    <span class="rounded-md bg-slate-900 px-3.5 py-2 text-[10px] font-semibold text-white shadow-sm">{{ __('Get a Free Quote') }}</span>
                                    <span class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3.5 py-2 text-[10px] font-semibold text-slate-800">
                                        <svg class="h-2.5 w-2.5 text-emerald-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 013.5 2h1.148a1.5 1.5 0 011.465 1.175l.716 3.223a1.5 1.5 0 01-1.052 1.767l-.933.267c.41 1.115 1.07 2.13 1.93 2.99s1.875 1.52 2.99 1.93l.267-.933a1.5 1.5 0 011.767-1.052l3.223.716A1.5 1.5 0 0118 15.352V16.5a1.5 1.5 0 01-1.5 1.5H15c-7.18 0-13-5.82-13-13V3.5z" clip-rule="evenodd" /></svg>
                                        {{ __('Call now') }}
                                    </span>
                                </div>
                            </div>
                            {{-- service / trust cards --}}
                            <div class="grid grid-cols-3 gap-2.5 px-6 pb-6 pt-2">
                                @foreach ([
                                    ['M13 10V3L4 14h7v7l9-11h-7z', __('24/7 Emergency'), __('Engineer within 60 min')],
                                    ['M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A2 2 0 014 7V5a2 2 0 012-2z', __('Fixed-Price Quotes'), __('No hidden call-out fees')],
                                    ['M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', __('12-Month Guarantee'), __('On all workmanship')],
                                ] as [$d, $t, $sub])
                                    <div class="rounded-lg border border-slate-200 bg-white p-3 text-center shadow-sm">
                                        <svg class="mx-auto h-4 w-4 text-orange-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" /></svg>
                                        <p class="mt-2 text-[8px] font-bold leading-tight text-slate-800">{{ $t }}</p>
                                        <p class="mt-1 text-[6.5px] leading-tight text-slate-500">{{ $sub }}</p>
                                    </div>
                                @endforeach
                            </div>
                            {{-- review / credentials bar --}}
                            <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50 px-6 py-2.5">
                                <div class="flex items-center gap-1">
                                    <span class="text-[9px] leading-none text-amber-500">★★★★★</span>
                                    <span class="text-[7px] font-semibold text-slate-600">{{ __('4.9 · 480 reviews') }}</span>
                                </div>
                                <div class="flex items-center gap-1.5 text-[6.5px] font-semibold uppercase tracking-wide text-slate-400">
                                    <span>Gas Safe</span><span class="text-slate-300">·</span><span>Which? Trusted</span><span class="text-slate-300">·</span><span>Checkatrade</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- callouts --}}
                    <ul class="mt-5 space-y-2.5 text-[13px] text-slate-700">
                        @foreach ([
                            __('Large, readable headline states the offer in one glance'),
                            __('Two clear calls-to-action above the fold'),
                            __('Click-to-call right in the header, one tap away'),
                            __('A single focused path: get a quote or call now'),
                            __('Trust signals like reviews, licensing and a guarantee build confidence'),
                        ] as $win)
                            <li class="flex items-start gap-2.5">
                                <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                <span>{{ $win }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            {{-- ── More before → after wins ──────────────────── --}}
            <div class="mt-16">
                <h3 class="text-center text-lg font-semibold text-slate-900">{{ __('More fixes that win back leads') }}</h3>
                <div class="mt-8 grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        [__('Dense, cramped text'), __('Large, scannable type'), __('Visitors actually read what you offer instead of leaving.')],
                        [__('Hidden contact details'), __('Click-to-call & sticky enquiry button'), __('Ready buyers reach you in one tap, not five clicks.')],
                        [__('No clear next step'), __('One strong call-to-action per page'), __('Every visitor knows exactly what to do next.')],
                        [__('Slow, heavy pages'), __('Fast, mobile-first build'), __('Fewer drop-offs before the page even finishes loading.')],
                        [__('Generic, dated design'), __('Modern, trustworthy look'), __('Ad clicks convert instead of bouncing on first impression.')],
                        [__('No social proof'), __('Reviews, logos & guarantees up front'), __('Trust that turns a visit into an enquiry.')],
                    ] as [$before, $after, $benefit])
                        <article class="bg-white p-6">
                            <p class="flex items-center gap-2 text-[12px] font-medium text-rose-600">
                                <svg class="h-3.5 w-3.5 flex-none" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                <span class="line-through decoration-rose-300">{{ $before }}</span>
                            </p>
                            <p class="mt-2 flex items-center gap-2 text-[13px] font-semibold text-slate-900">
                                <svg class="h-3.5 w-3.5 flex-none text-emerald-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                {{ $after }}
                            </p>
                            <p class="mt-2 text-[12px] leading-6 text-slate-600">{{ $benefit }}</p>
                        </article>
                    @endforeach
                </div>
            </div>

            <div class="mt-12 text-center">
                <a href="{{ $bookingUrl }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('See what your revamp could look like') }}</a>
            </div>
        </div>
    </section>

    {{-- ── Process ───────────────────────────────────────────── --}}
    <section id="process" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('How it works') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('Done for you, built around results.') }}</h2>
            </div>
            <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['01', __('Strategy call & audit'), __('A 15-minute call and a proper look at your site and ad pages. We find where you’re losing leads and how much it’s worth fixing.')],
                    ['02', __('Plan'), __('A clear plan covering the redesign, the conversion fixes and the SEO work, with the leads each change should bring. We agree it before any building starts.')],
                    ['03', __('Design & build'), __('We redesign and rebuild your pages with a modern look, clear ways to contact you or buy, and SEO and speed built in. You review and approve as we go.')],
                    ['04', __('Launch & measure'), __('A safe launch with no lost rankings, then before-and-after numbers on leads, conversions, ad performance and rankings, tracked in Serfix so the growth keeps building.')],
                ] as [$n, $t, $d])
                    <li class="rounded-2xl border border-slate-200 bg-white p-6">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-900 text-sm font-semibold tabular-nums text-white">{{ $n }}</span>
                        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ $t }}</h3>
                        <p class="mt-2 text-[13px] leading-6 text-slate-600">{{ $d }}</p>
                    </li>
                @endforeach
            </ol>
            <div class="mt-10 text-center">
                <a href="{{ $bookingUrl }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Book your strategy call') }}</a>
            </div>
        </div>
    </section>

    {{-- ── Results ───────────────────────────────────────────── --}}
    <section id="outcomes" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Measured results') }}</p>
                    <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]">{{ __('More enquiries from the same traffic.') }}</h2>
                    <p class="mt-4 text-[15px] leading-7 text-slate-600">
                        {{ __('We measure your leads, conversion rate and ad performance before we touch anything, then show you the lift once the new site is live. The aim is simple: more customers getting in touch, both from the visitors you already have and the new ones SEO brings in.') }}
                    </p>
                    <ul class="mt-7 space-y-3 text-[14px] text-slate-700">
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('More leads: calls, forms, and purchases') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Higher conversion rate from ad and organic traffic') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('Lower cost per lead on your campaigns') }}</li>
                        <li class="flex gap-2.5"><span class="mt-1.5 h-1 w-1 flex-none rounded-full bg-slate-400"></span>{{ __('More organic traffic from improved SEO') }}</li>
                    </ul>
                </div>

                {{-- Mockup: growth result --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">{{ __('Revamp result · example.com') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ __('First 90 days · before → after') }}</p>
                        </div>
                        <span class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">{{ __('Leads +71%') }}</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @foreach ([
                            [__('Leads · mo'), '41', '142'],
                            [__('Conversion rate'), '1.0%', '2.6%'],
                            [__('Cost per lead'), '$58', '$22'],
                            [__('Organic clicks · mo'), '2.7k', '6.4k'],
                        ] as [$l, $before, $after])
                            <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50/60 px-4 py-2.5 text-[12px]">
                                <span class="font-semibold uppercase tracking-wider text-slate-500">{{ $l }}</span>
                                <span class="flex items-center gap-2 tabular-nums">
                                    <span class="text-slate-400">{{ $before }}</span>
                                    <svg class="h-3 w-3 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                    <span class="font-semibold text-emerald-600">{{ $after }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-center text-[10px] text-slate-400">{{ __('Illustrative result. Actual gains depend on your starting point and market.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ───────────────────────────────────────────────── --}}
    <section id="faq" class="bg-slate-50/60 py-20 sm:py-24">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('FAQ') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('Questions, answered.') }}</h2>
            </div>
            <div class="mt-12 divide-y divide-slate-200 border-y border-slate-200">
                @foreach ($revampFaqs as [$q, $a])
                    <details class="group py-5">
                        <summary class="flex cursor-pointer items-center justify-between gap-4 text-[15px] font-semibold text-slate-900 marker:content-none">
                            {{ $q }}
                            <svg class="h-4 w-4 flex-none text-slate-400 transition group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                        </summary>
                        <p class="mt-3 text-[14px] leading-7 text-slate-600">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA ───────────────────────────────────────────────── --}}
    <section class="bg-white pb-20 sm:pb-24">
        <div class="mx-auto max-w-4xl px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-slate-50/60 px-6 py-14 text-center sm:px-12">
                <h2 class="text-balance text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ __('Stop losing customers at your website.') }}</h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">{{ __('Book a free 15-minute strategy call. We’ll review your site live and show you exactly where leads are slipping away.') }}</p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ $bookingUrl }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Book a free strategy call') }}</a>
                    <a href="{{ route('tools.audit') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">{{ __('Get a free site audit') }}</a>
                </div>
            </div>
        </div>
    </section>
</x-marketing.page>
