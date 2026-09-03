<x-marketing.page
    title="Guide — Serfix Content AI"
    description="The complete client guide to Serfix Content AI: onboarding, the content calendar, reviewing and editing articles, publishing to your website, keyword research, rank tracking, settings — plus fixes for the most common problems."
    active="guide"
>
    @php
        // Annotated product screenshots produced from the real UI with demo
        // data (public/images/guide/*.webp — regenerated via MarketingShotsTest
        // + the guide renderer; never client data).
        $shot = fn (string $name) => asset('images/guide/'.$name.'.webp');
    @endphp

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('User guide · Content AI') }}</p>
            <h1 class="mt-4 text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                {{ __('Everything Serfix does for your website, explained.') }}
            </h1>
            <p class="mt-5 text-balance text-[17px] leading-8 text-slate-600">
                {{ __('Serfix researches what your customers search for, writes an expert article about it, and publishes it on your website — automatically, on a schedule you control. This guide walks through every screen with real screenshots, then covers the problems people actually run into and how to fix them.') }}
            </p>
            <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                <a href="#onboarding" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Start reading') }}</a>
                <a href="#troubleshooting" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900">{{ __('Jump to problem-solving') }}</a>
            </div>
        </div>
    </section>

    {{-- ── Two-column body: TOC + content ───────────────────── --}}
    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-12 px-6 py-16 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-16 lg:px-8 lg:py-20">

            {{-- Sticky TOC. min-w-0 on both grid children: without it the
                 implicit single-column track (mobile) grows to the widest
                 image's min-width → page-level sideways scroll on phones. --}}
            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('In this guide') }}</p>
                <nav aria-label="{{ __('Guide sections') }}" class="mt-3 flex flex-col gap-1 text-sm">
                    @php
                        $tocStart = [
                            ['#onboarding', '01', __('Set up in about 2 minutes')],
                            ['#calendar', '02', __('Your content calendar')],
                            ['#review', '03', __('Review and edit articles')],
                            ['#publishing', '04', __('Connect your website')],
                        ];
                        $tocGrow = [
                            ['#research', __('Keyword ideas')],
                            ['#tracker', __('Track your rankings')],
                            ['#settings', __('Tune every article')],
                            ['#sharing', __('Auto-share to social')],
                            ['#support', __('Get help fast')],
                        ];
                        $tocHelp = [
                            ['#troubleshooting', __('Common problems, solved')],
                            ['#faq', __('FAQ')],
                        ];
                    @endphp
                    @foreach ($tocStart as [$href, $num, $label])
                        <a href="{{ $href }}" class="flex items-baseline gap-2 rounded-md px-2 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                            <span class="font-mono text-[11px] text-slate-400">{{ $num }}</span>{{ $label }}
                        </a>
                    @endforeach
                    <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Day to day') }}</p>
                    @foreach ($tocGrow as [$href, $label])
                        <a href="{{ $href }}" class="rounded-md px-2 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">{{ $label }}</a>
                    @endforeach
                    <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('When something is off') }}</p>
                    @foreach ($tocHelp as [$href, $label])
                        <a href="{{ $href }}" class="rounded-md px-2 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">{{ $label }}</a>
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0 space-y-20">

                {{-- ═══ 01 · Onboarding ═══ --}}
                <article id="onboarding" class="scroll-mt-24">
                    <p class="font-mono text-xs text-slate-400">01</p>
                    <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Set up in about 2 minutes') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Enter your website address and Serfix reads your site to work out what you do, who it’s for, and what you sell. You then walk through seven short steps — everything is pre-filled, you only correct what’s wrong. The better this profile, the better every article that follows.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('wizard-business') }}" alt="{{ __('Onboarding step 1 — business profile with website type and description') }}" loading="lazy" class="w-full">
                    </figure>
                    <ol class="mt-6 space-y-3 leading-7 text-slate-600">
                        <li><span class="font-semibold text-slate-900">{{ __('Business') }}</span> — {{ __('confirm your brand name, article language, target country and what your business does. Pick the website type that fits — it shapes your keywords, competitors and writing style.') }}</li>
                        <li><span class="font-semibold text-slate-900">{{ __('Offerings') }}</span> — {{ __('list what you sell and, just as important, what you don’t. Articles will steer readers toward the first list and never promise the second.') }}</li>
                        <li><span class="font-semibold text-slate-900">{{ __('Article structure') }}</span> — {{ __('choose which sections every article carries: featured image, key takeaways, a clickable “In this article” list, and an FAQ.') }}</li>
                        <li><span class="font-semibold text-slate-900">{{ __('Images') }}</span> — {{ __('pick a visual style for generated images, from realistic photography to flat illustration — or turn images off entirely.') }}</li>
                        <li><span class="font-semibold text-slate-900">{{ __('Competitors') }}</span> — {{ __('we find who actually competes with you on Google. Remove anyone who isn’t a rival and add anyone we missed.') }}</li>
                        <li><span class="font-semibold text-slate-900">{{ __('Keyword research') }}</span> — {{ __('see the searches your plan is built on: what your audience types into Google, how often, and where you already show up.') }}</li>
                        <li><span class="font-semibold text-slate-900">{{ __('First articles') }}</span> — {{ __('your first month of topics appears on a calendar, ready to write.') }}</li>
                    </ol>
                    <p class="mt-4 text-sm leading-6 text-slate-500">{{ __('Every choice here can be changed later in Content Settings — nothing is locked in.') }}</p>
                </article>

                {{-- ═══ 02 · Calendar ═══ --}}
                <article id="calendar" class="scroll-mt-24">
                    <p class="font-mono text-xs text-slate-400">02</p>
                    <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Your content calendar') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('The calendar is home base: a month of planned articles, each on its own day. Cards move through clear stages — Planned, Writing, Ready for review, Scheduled, Published — so one glance tells you what shipped and what’s coming.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('calendar') }}" alt="{{ __('The content calendar — a month of planned and published articles') }}" loading="lazy" class="w-full">
                    </figure>
                    <ul class="mt-5 space-y-2.5 text-[15px] leading-7 text-slate-600">
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">1</span><span>{{ __('Move between months with the arrows — past months show what shipped, future months show what’s planned.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">2</span><span>{{ __('Switch between the month grid and a list view. The list is best on a phone and for changing dates.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">3</span><span>{{ __('Your publish window — articles go live between these hours, in your timezone. Change it any time.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">4</span><span>{{ __('Don’t want to wait for the scheduled day? “Write” starts an article immediately and shows live progress: research, writing, quality checks, images.') }}</span></li>
                    </ul>
                    <p class="mt-5 leading-7 text-slate-600">
                        {{ __('Drag a card to another day to reschedule it, or use the small calendar icon on the card to pick an exact date — including a day that already has an article. Every card shows its quality score, word count and image count before you even open it.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('calendar-list') }}" alt="{{ __('List view of the calendar with per-article actions') }}" loading="lazy" class="w-full">
                        <figcaption class="border-t border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-500">
                            {{ __('List view: ① Review opens the full article. ② Republish sends an already-published article to your site again — useful after edits or if your site lost it.') }}
                        </figcaption>
                    </figure>
                    <div class="mt-6 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm leading-6 text-slate-700">
                        <span class="font-semibold text-orange-700">{{ __('Good to know:') }}</span>
                        {{ __('each plan includes a monthly article allowance. Articles over the allowance are marked in red and simply wait for the next month — nothing is lost.') }}
                    </div>
                </article>

                {{-- ═══ 03 · Review & edit ═══ --}}
                <article id="review" class="scroll-mt-24">
                    <p class="font-mono text-xs text-slate-400">03</p>
                    <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Review and edit articles') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Click any card and you get the full article the way a reader will see it — plus everything you need to judge it in ten seconds: a quality score out of 100, the exact searches it targets, a realistic estimate of the monthly visitors it can bring, and a preview of how it will look on Google.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('article-review') }}" alt="{{ __('The article page — quality score, SEO targets, search preview and the article itself') }}" loading="lazy" class="w-full">
                    </figure>
                    <ul class="mt-5 space-y-2.5 text-[15px] leading-7 text-slate-600">
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">1</span><span>{{ __('The quality score. Every draft is checked against dozens of writing and SEO rules and rewritten until it passes — anything failing is listed here in plain language.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">2</span><span>{{ __('One click adds the article’s search phrases to your rank tracker, so you can watch the article climb Google week by week.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">3</span><span>{{ __('Exactly how the article will appear in Google results — title, address and description. All three are editable.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">4</span><span>{{ __('Open the editor to change anything yourself.') }}</span></li>
                    </ul>
                    <h3 class="mt-8 text-lg font-semibold text-slate-900">{{ __('The editor') }}</h3>
                    <p class="mt-2 leading-7 text-slate-600">
                        {{ __('The editor works like a modern word processor: headings, bold, links, lists, tables and images, editing the article exactly as it will appear. Select any sentence and ask the built-in assistant to rewrite, shorten or expand just that part. You can also replace any image — upload your own, paste an image address, or generate a new one in your chosen style.') }}
                    </p>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Below the editor sit the same search-engine fields professional SEO plugins offer: focus keyphrase, page address, canonical link, and how the article looks when shared on social networks. Serfix fills all of them for you — they’re there when you want control, invisible when you don’t.') }}
                    </p>
                    <h3 class="mt-8 text-lg font-semibold text-slate-900">{{ __('Tell us what you think — it changes the next article') }}</h3>
                    <figure class="mt-4 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('article-feedback') }}" alt="{{ __('The article feedback bar — love it, needs rewrites, or fundamentally wrong') }}" loading="lazy" class="w-full">
                    </figure>
                    <p class="mt-4 leading-7 text-slate-600">
                        {{ __('Every article carries a quick feedback bar. Use it — it goes straight to our team, and “needs rewrites” or “fundamentally wrong” with a short comment is the fastest way to get the writing corrected for your site.') }}
                    </p>
                    <div class="mt-6 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm leading-6 text-slate-700">
                        <span class="font-semibold text-orange-700">{{ __('Hands-off or hands-on — your choice:') }}</span>
                        {{ __('with auto-publish on, a finished article waits through a review window (24 hours unless you change it) and then goes live by itself. Prefer to approve everything? Turn auto-publish off and nothing ships until you press Publish.') }}
                    </div>
                </article>

                {{-- ═══ 04 · Publishing ═══ --}}
                <article id="publishing" class="scroll-mt-24">
                    <p class="font-mono text-xs text-slate-400">04</p>
                    <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Connect your website') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Articles publish straight onto your own website — your domain gets the traffic and the Google authority. Connect once and every approved article lands on your site automatically: text, images, headings and all the search-engine details, formatted for your platform.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('integrations') }}" alt="{{ __('The Integrations page — eight publishing destinations with step-by-step connect guides') }}" loading="lazy" class="w-full">
                    </figure>
                    <ul class="mt-5 space-y-2.5 text-[15px] leading-7 text-slate-600">
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">1</span><span>{{ __('Hands-off publishing — the same auto-publish switch as on the article page, right where you connect.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">2</span><span>{{ __('Pick your platform — each one comes with an illustrated step-by-step connect guide right on the page, like the WordPress one shown here.') }}</span></li>
                    </ul>
                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        @foreach ([
                            ['WordPress', __('Connects with an application password — a special password WordPress generates that never exposes your real login. Articles arrive as normal posts with the featured image set.')],
                            ['Shopify', __('Publishes into your store’s blog. If you run several blogs, you choose which one during connect.')],
                            ['Webflow', __('Publishes into the CMS collection you choose, mapping the article into your collection’s fields.')],
                            ['Wix', __('Publishes into your Wix blog, with images imported into your Wix media library.')],
                            ['HubSpot', __('Publishes into your HubSpot blog as draft or live — your choice during connect.')],
                            ['Sanity', __('Creates a post document in the dataset you pick, ready for your site to render.')],
                            ['Medusa', __('For Medusa stores: we provide ready-made files that add a blog to your store — paste them in once and articles publish automatically, with blog pages for your storefront included.')],
                            ['Laravel', __('A small package for your developers — articles arrive at your site over a signed, secure connection.')],
                            [__('Custom (webhook)'), __('Works with any platform: we send each article to an address your developer provides, cryptographically signed so only Serfix can post to it.')],
                        ] as [$platform, $desc])
                            <div class="rounded-xl border border-slate-200 p-4">
                                <p class="text-sm font-semibold text-slate-900">{{ $platform }}</p>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $desc }}</p>
                            </div>
                        @endforeach
                    </div>
                    <h3 class="mt-8 text-lg font-semibold text-slate-900">{{ __('Prove the connection actually works') }}</h3>
                    <p class="mt-2 leading-7 text-slate-600">
                        {{ __('For custom connections there’s a built-in tester: it sends a complete sample article — every field filled in — through the exact same path real articles take. You can point it at a test address first, so your developer can inspect the payload without touching your live site.') }}
                    </p>
                    <figure class="mt-4 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('webhook-tester') }}" alt="{{ __('The webhook tester — send a full sample article to any test address') }}" loading="lazy" class="w-full">
                    </figure>
                    <div class="mt-6 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm leading-6 text-slate-700">
                        <span class="font-semibold text-orange-700">{{ __('After publishing:') }}</span>
                        {{ __('Serfix confirms the article is really live on your site, asks Google to index it if your Search Console is connected, emails you a “your article is live” note, and starts tracking its rankings — all automatic.') }}
                    </div>
                </article>

                {{-- ═══ Research ═══ --}}
                <article id="research" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('Keyword ideas that never run dry') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('The Research page is a live feed of article opportunities: searches your audience already types into Google, refreshed continuously. Each one shows how many people search it monthly and — because difficulty is measured against your site, not a generic average — whether it’s an easy win for you specifically.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('research') }}" alt="{{ __('The Research page — vetted keyword ideas with difficulty and one-click add to calendar') }}" loading="lazy" class="w-full">
                        <figcaption class="border-t border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-500">
                            {{ __('① Add to calendar shows you the available days — pick one and the article is planned. Full months roll into the next month automatically.') }}
                        </figcaption>
                    </figure>
                    <p class="mt-5 leading-7 text-slate-600">
                        {{ __('Filter by intent (informational, commercial, transactional), by difficulty, or search within the list. The “Questions your audience asks” strip at the bottom shows real questions from Google — each one a ready-made article.') }}
                    </p>
                </article>

                {{-- ═══ Tracker ═══ --}}
                <article id="tracker" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('Track your rankings — and what they’re worth') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('The Content Tracker answers the only question that matters: is this working? It watches where your articles rank in Google for their target searches, how many people saw them, and how many clicked through — updated from Google’s own data.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('tracker') }}" alt="{{ __('The Content Tracker — positions, clicks, impressions and the value your articles brought') }}" loading="lazy" class="w-full">
                    </figure>
                    <ul class="mt-5 space-y-2.5 text-[15px] leading-7 text-slate-600">
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">1</span><span>{{ __('Choose the country whose Google results matter to you. Positions are re-checked every 7 days — changing the country re-checks everything immediately.') }}</span></li>
                        <li class="flex gap-3"><span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-600 text-[11px] font-bold text-white">2</span><span>{{ __('The headline: combined visits and appearances your published articles earned from Google in the last 30 days.') }}</span></li>
                    </ul>
                    <p class="mt-5 leading-7 text-slate-600">
                        {{ __('Under each article you’ll also find the extra searches it turned out to rank for — phrases you never targeted. One click tracks any of them. Click a keyword to open its full history: a chart of its climb, week by week, with the exact page that ranks.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('rank-history') }}" alt="{{ __('A keyword’s rank history — the climb from page 6 to page 1 charted over time') }}" loading="lazy" class="w-full">
                    </figure>
                    <div class="mt-6 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm leading-6 text-slate-700">
                        <span class="font-semibold text-orange-700">{{ __('You’ll hear about the wins:') }}</span>
                        {{ __('when your keywords move up meaningfully — first page, top 3, big jumps — Serfix emails you a short digest. No noise, just milestones.') }}
                    </div>
                </article>

                {{-- ═══ Settings ═══ --}}
                <article id="settings" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('Tune every article from one place') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Content Settings is where you shape what Serfix writes — six tabs, each applying to every future article. Nothing here requires a developer.') }}
                    </p>
                    <div class="mt-5 space-y-2 text-[15px] leading-7 text-slate-600">
                        <p><span class="font-semibold text-slate-900">{{ __('Business profile') }}</span> — {{ __('your description, article language and target country. Rewrite the description any time your positioning changes.') }}</p>
                        <p><span class="font-semibold text-slate-900">{{ __('Offerings') }}</span> — {{ __('the “we sell / we don’t sell” lists that keep articles honest about what you offer.') }}</p>
                        <p><span class="font-semibold text-slate-900">{{ __('Article structure') }}</span> — {{ __('switch the featured image, key takeaways, table of contents and FAQ sections on or off.') }}</p>
                        <p><span class="font-semibold text-slate-900">{{ __('Images') }}</span> — {{ __('turn generated images on or off and pick one of nine visual styles.') }}</p>
                        <p><span class="font-semibold text-slate-900">{{ __('Publishing') }}</span> — {{ __('articles per week, article length, the publish window, and the auto-publish review period.') }}</p>
                        <p><span class="font-semibold text-slate-900">{{ __('Brand protection') }}</span> — {{ __('controls whether competitors may ever be named in your articles, and how strictly.') }}</p>
                    </div>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('settings-structure') }}" alt="{{ __('Article structure settings — featured image, takeaways, contents list and FAQ toggles') }}" loading="lazy" class="w-full">
                        <figcaption class="border-t border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-500">
                            {{ __('① The “Featured image in article” switch — turn it off if your theme already shows the featured image and you’re seeing it twice.') }}
                        </figcaption>
                    </figure>
                    <figure class="mt-4 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('settings-images') }}" alt="{{ __('Image settings — master switch and nine visual styles') }}" loading="lazy" class="w-full">
                    </figure>
                    <figure class="mt-4 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('settings-publishing') }}" alt="{{ __('Publishing settings — cadence, article length and publish window') }}" loading="lazy" class="w-full">
                    </figure>
                </article>

                {{-- ═══ Auto-share ═══ --}}
                <article id="sharing" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('Auto-share to social') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Connect your Facebook page, X account or Pinterest and every article that goes live on your site is shared there automatically — the real link on your domain, with the featured image. Set it up once under Auto-share in the menu; posts only ever use the article’s live address, never a draft.') }}
                    </p>
                </article>

                {{-- ═══ Support ═══ --}}
                <article id="support" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('Get help fast') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('Support lives inside the app. Open a ticket, write your question — with formatting and links if you need them — and every reply lands both in the app and in your email inbox. Answer from either place; it’s one conversation. Bug reports you file with the bug button become tickets automatically, so nothing gets lost.') }}
                    </p>
                    <figure class="mt-6 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                        <img src="{{ $shot('support') }}" alt="{{ __('The Support page — tickets with status, answered here and by email') }}" loading="lazy" class="w-full">
                    </figure>
                </article>

                {{-- ═══ Troubleshooting ═══ --}}
                <article id="troubleshooting" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('Common problems, solved') }}</h2>
                    <p class="mt-3 leading-7 text-slate-600">
                        {{ __('The issues clients actually hit, with the fix for each. If yours isn’t here, open a Support ticket — that’s what it’s for.') }}
                    </p>

                    @php
                        $troubleGroups = [
                            [__('Connecting WordPress'), [
                                [__('“Could not verify” when connecting'), __('Use an Application Password, not your normal login password. In WordPress go to Users → Profile → Application Passwords, create one named “Serfix”, and paste it with the spaces — they’re part of the password. The username is your WordPress username (often looks like an email), not your display name.')],
                                [__('Verified fine, but publishing fails'), __('The WordPress account needs to be an Author, Editor or Administrator — Subscribers and Contributors can’t publish posts. Also check that a security plugin isn’t blocking the WordPress connection (REST API): whitelisting “application passwords” or the /wp-json path in your security plugin fixes it.')],
                                [__('The featured image shows twice on my posts'), __('Your theme already displays the featured image above the post, and the article body includes it too. Turn off Content Settings → Article structure → “Featured image in article” — future articles will carry the image only where your theme puts it.')],
                            ]],
                            [__('Connecting Shopify, Webflow, Wix, HubSpot or Sanity'), [
                                [__('Shopify: “invalid token” or nothing publishes'), __('The access token must come from a custom app in your Shopify admin with permission to read and write your online store’s blog content, and the store address must be the one ending in .myshopify.com. If your store has several blogs, re-check the connection and pick the right blog when asked.')],
                                [__('Webflow: connected, but the article’s link shows a 404'), __('Your CMS collection needs a template page designed and published in Webflow — until it exists, items in that collection have no public page. Also make sure the collection has a rich-text field for the article body; we’ll tell you during connect if it doesn’t.')],
                                [__('Wix: “blog not available” during connect'), __('The Wix Blog app must be installed on your site (Wix → App Market → Wix Blog). Connect again after installing, and pick the author the posts should appear under.')],
                                [__('HubSpot: articles arrive but stay drafts'), __('Two things to check: your HubSpot blog needs an author (we create one if we can, but some accounts restrict this), and the connection’s “publish live” choice controls draft vs live. Re-check the connection and choose live publishing if that’s what you want.')],
                                [__('Sanity: connected, but I can’t see the articles on my site'), __('Articles are created as documents in your dataset — your site decides how to render them. Ask your developer to confirm the document type matches what your site queries. They’ll find each article in your studio, fully populated.')],
                                [__('Medusa: the connection test fails'), __('A “route not found” message means the receiver files from the setup guide aren’t deployed (or Medusa wasn’t restarted after adding them, or the migration wasn’t run). A “signature” message means the SERFIX_SECRET on your Medusa server doesn’t match the signing secret in Serfix — the two values must be identical.')],
                            ]],
                            [__('My website isn’t one of these'), [
                                [__('My site is built with Hostinger Horizon, and I can’t connect it'), __('Automatic posting works by adding the post to your website for you, which needs your site to offer a way in for outside tools. Site builders like Hostinger Horizon build and host the page themselves and don’t offer that, so no tool can post into them automatically. You have two good options, both below — the first keeps everything automatic.')],
                                [__('Best fix: add a blog to your existing site (still fully automatic)'), __('Most hosting plans already include WordPress at no extra cost. Set it up on an address like blog.yourdomain.com, then connect it here under Settings → Publishing. Your existing site stays exactly as it is — you just link to the blog from your menu — and you get the whole service back: articles published on schedule, images added, SEO fields filled in, and every new post submitted to Google. If you move your main site later, the blog comes with you.')],
                                [__('Second option: publish by hand in about a minute'), __('Open any finished article and use “Take it elsewhere” in the right-hand column: Copy HTML, Copy Markdown, or download the file. Paste it into your site’s page editor. Most builders have an HTML or embed block — paste the HTML there and it keeps your headings, links and images. Everything before that step still runs automatically.')],
                                [__('If I paste articles myself, what still works?'), __('Everything except the final step. We still research topics, write and illustrate the articles, score them and keep your calendar. Two things become manual: the article won’t be marked as published here (so use it as your to-do list), and rank tracking won’t start on its own — open the article and use the tracking panel, or add the keyword in the Tracker, and positions are followed from then on.')],
                                [__('Squarespace, Framer, a custom-built site — same answer?'), __('Yes. The question is always whether your site offers a way for outside tools to add posts. If it does, tell us what you use and we’ll look at supporting it. If it doesn’t, the blog-subdomain route above is the reliable way to keep publishing automatic.')],
                            ]],
                            [__('Custom webhook / Laravel connections'), [
                                [__('“Verified” but articles never appear on the site'), __('Verification proves your endpoint answers; it doesn’t prove it saves articles. Use the webhook tester to send a full sample article, then check whether it appears. If your endpoint answers OK but drops the data, your developer will see exactly which fields arrive in the sample payload.')],
                                [__('How does my developer secure the endpoint?'), __('Every delivery is signed with your connection’s secret. The receiver should verify the signature and answer with a success status; replying with the article’s final address lets Serfix confirm the article is live and start tracking it.')],
                                [__('I want to test without touching the live site'), __('The tester’s address field is editable — point it at any test address (a request-inspection service works well). It only affects the test; your saved connection is untouched.')],
                            ]],
                            [__('Images'), [
                                [__('I don’t want images at all'), __('Content Settings → Images → switch off. Future articles come as pure text.')],
                                [__('The images feel repetitive or off-brand'), __('Pick a different style under Content Settings → Images — nine styles from photography to watercolor. For a single article, open it in the editor and replace any image: upload your own, paste an image address, or generate a fresh one.')],
                                [__('An article published without its images'), __('Images are finalized just before publishing; if you hit “Publish now” during generation the text ships first. Use Republish on the calendar once the card stops showing “Finalizing images” — the article updates in place on your site.')],
                            ]],
                            [__('Publishing and scheduling'), [
                                [__('An article missed its scheduled day'), __('Articles publish inside your daily publish window. If the window was already over when the article became ready (or approval came late), it ships as soon as possible afterwards. Check the window under Content Settings → Publishing.')],
                                [__('I edited an article after it was published'), __('Press Republish on its calendar card — destinations that already have the article receive the updated version in place, same address.')],
                                [__('Some cards are red — “Over monthly limit”'), __('Your plan writes a fixed number of articles per month; red cards are queued beyond that. They’ll write next month, or you can move them there yourself — or upgrade the plan if you want more per month.')],
                                [__('How do I move an article to another month?'), __('Switch to List view, pick a new date next to the article and save. Any future date works.')],
                            ]],
                            [__('Rankings and data'), [
                                [__('The tracker says “Checking…” forever'), __('First checks run within a few minutes. If you just changed the tracking country, every keyword re-checks — give it a few minutes and refresh.')],
                                [__('Positions look different from what I see in Google'), __('Google personalizes results by location, history and device. The tracker checks neutral results from your chosen country — the honest average, not your own bubble. Pick the country your customers are in for the most meaningful numbers.')],
                                [__('Clicks and impressions are empty'), __('That data comes from your Google Search Console connection (Settings → Integrations). After connecting, Google usually needs a day or two before data flows. New articles also take a few days to earn their first impressions.')],
                                [__('Why can’t I add more keywords?'), __('Tracking has a per-website capacity shown at the top of the tracker. Delete keywords you no longer care about to free slots.')],
                            ]],
                            [__('Account and plan'), [
                                [__('I want articles in another language or country'), __('Content Settings → Business profile — set article language and target country. New research and articles follow immediately.')],
                                [__('Competitors are being mentioned in my articles'), __('Content Settings → Brand protection decides whether competitors may appear at all, and how strictly. Set it to protective and future articles won’t name them.')],
                                [__('Adding a second website'), __('Websites in the menu → add your site, then run the same 2-minute setup. Each website has its own calendar, settings, integrations and tracker.')],
                            ]],
                        ];
                    @endphp
                    <div class="mt-6 space-y-6">
                        @foreach ($troubleGroups as [$group, $items])
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ $group }}</h3>
                                <div class="mt-3 space-y-2">
                                    @foreach ($items as [$q, $a])
                                        <details class="group rounded-xl border border-slate-200 bg-white">
                                            <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
                                                {{ $q }}
                                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                            </summary>
                                            <p class="border-t border-slate-100 px-4 py-3 text-sm leading-6 text-slate-600">{{ $a }}</p>
                                        </details>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>

                {{-- ═══ FAQ ═══ --}}
                <article id="faq" class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('FAQ') }}</h2>
                    <div class="mt-5 space-y-2">
                        @foreach ([
                            [__('Do I have to do anything day to day?'), __('No. With a connected website and auto-publish on, Serfix researches, writes, illustrates and publishes on schedule. Most clients check the calendar once or twice a week and read the emails.')],
                            [__('Will the articles read like AI wrote them?'), __('Every article passes a natural-writing check before you ever see it — drafts that sound robotic are rewritten automatically. You also have the feedback bar on every article, and our team reads it.')],
                            [__('Who owns the content?'), __('You do. Articles publish on your domain, under your brand. Cancel any time — everything already published stays yours.')],
                            [__('Does it work in my language?'), __('Articles can be written in over 40 languages, with keyword research targeted to the country you choose.')],
                            [__('What do I need to get started?'), __('Just your website address. Connecting Google Search Console is optional but recommended — it unlocks click and impression data in your tracker.')],
                            [__('Can my agency manage several client sites?'), __('Yes — each website is fully independent: its own calendar, profile, integrations and tracker. Add as many sites as your plan allows.')],
                        ] as [$q, $a])
                            <details class="group rounded-xl border border-slate-200 bg-white">
                                <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
                                    {{ $q }}
                                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </summary>
                                <p class="border-t border-slate-100 px-4 py-3 text-sm leading-6 text-slate-600">{{ $a }}</p>
                            </details>
                        @endforeach
                    </div>
                </article>

                {{-- ═══ CTA ═══ --}}
                <div class="rounded-2xl bg-slate-900 px-6 py-10 text-center sm:px-10">
                    <h2 class="text-2xl font-semibold tracking-tight text-white">{{ __('See it on your own website') }}</h2>
                    <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-slate-300">
                        {{ __('Enter your website address and your first articles are planned in about 2 minutes — free trial, no card needed.') }}</p>
                    <div class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <a href="{{ route('content.landing') }}" class="inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition hover:brightness-110">{{ __('Get started free') }}</a>
                        <a href="{{ route('content.pricing') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-700 px-6 py-3 text-sm font-semibold text-slate-200 transition hover:border-slate-500">{{ __('See pricing') }}</a>
                    </div>
                </div>

            </div>
        </div>
    </section>
</x-marketing.page>
