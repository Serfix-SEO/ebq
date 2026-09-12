{{-- Publishing destinations + the work this removes.

     The destination list is the REAL driver set (App\Models\ContentIntegration
     platform constants + app/Services/Content/Publishing/*Driver.php). The old
     page said only "WordPress or any platform via webhook", which undersold
     eight shipped integrations. Label them accurately — WordPress connects
     with a secure application password; the rest are token-paste — so support
     never inherits a claim the product cannot meet. --}}
<section class="border-b border-slate-200 bg-white">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="grid gap-12 lg:grid-cols-2 lg:gap-16">

            {{-- Left: where it publishes --}}
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Publishing') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Publish wherever your website lives.') }}</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">{{ __('Connect your website once. Review your content, approve it, and publish without moving articles between tools.') }}</p>

                <div class="mt-10 rounded-3xl border border-slate-200 bg-slate-50 p-6 lg:p-8">
                    {{-- The hub. Pills rather than third-party logos: we hold no
                         licence to redistribute their marks, and a wordmark is
                         just as legible. --}}
                    <div class="flex flex-col items-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-xl font-extrabold text-white shadow-lg shadow-orange-600/25">S</span>
                        <span class="mt-2 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('Serfix') }}</span>
                        <span class="mt-3 h-6 w-px bg-gradient-to-b from-orange-300 to-slate-200"></span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                        @foreach ([
                            'WordPress', 'Shopify', 'Webflow',
                            'Wix', 'HubSpot', 'Sanity',
                            'Medusa', 'Laravel', __('Custom webhook'),
                        ] as $platform)
                            <span class="flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-center text-xs font-semibold text-slate-700 shadow-sm">{{ $platform }}</span>
                        @endforeach
                    </div>

                    <p class="mt-5 text-xs leading-5 text-slate-500">{{ __('Images upload into your own media library, and the SEO fields and schema travel with the post.') }}</p>
                </div>
            </div>

            {{-- Right: the work it removes, and what that is worth --}}
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Less work to manage') }}</p>
                <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('Spend less time on SEO. More time on growth.') }}</h2>

                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Doing it manually') }}</p>
                        <ul class="mt-3 space-y-1.5">
                            @foreach ([
                                __('Research keywords'), __('Analyze competitors'), __('Build a strategy'),
                                __('Manage writers'), __('Choose images'), __('Optimize articles'),
                                __('Publish manually'), __('Check rankings'), __('Create reports'),
                            ] as $chore)
                                <li class="flex items-start gap-2 text-xs leading-5 text-slate-500">
                                    <svg class="mt-1 h-3 w-3 flex-none text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    {{ $chore }}
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="rounded-2xl border-2 border-orange-200 bg-orange-50/50 p-5">
                        <p class="text-xs font-bold uppercase tracking-wider text-orange-600">{{ __('With Serfix') }}</p>
                        <ul class="mt-3 space-y-1.5">
                            @foreach ([
                                __('Review the strategy'), __('Approve the content'), __('Publish'),
                            ] as $task)
                                <li class="flex items-start gap-2 text-xs font-medium leading-5 text-slate-700">
                                    <svg class="mt-0.5 h-3.5 w-3.5 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    {{ $task }}
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-4 border-t border-orange-200/70 pt-3 text-[11px] leading-4 text-slate-500">{{ __('Everything else runs on its own, on the schedule you set.') }}</p>
                    </div>
                </div>

                {{-- What the same output costs the usual ways. The Serfix figure
                     is config-driven so an admin price change moves it. --}}
                <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
                    @foreach ([
                        ['label' => __('Freelance writer'), 'price' => '$1,200+', 'unit' => __('per month, typical'), 'highlight' => false],
                        ['label' => __('Content agency'), 'price' => '$3,000+', 'unit' => __('per month, typical'), 'highlight' => false],
                        ['label' => __('Serfix'), 'price' => '$'.$annual, 'unit' => __('per month, billed yearly'), 'highlight' => true],
                    ] as $row)
                        <div @class([
                            'flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 last:border-b-0',
                            'bg-white' => ! $row['highlight'],
                            'bg-slate-900' => $row['highlight'],
                        ])>
                            <span @class(['text-sm font-semibold', 'text-slate-600' => ! $row['highlight'], 'text-white' => $row['highlight']])>{{ $row['label'] }}</span>
                            <span class="text-end">
                                <span @class(['block text-lg font-extrabold tracking-tight', 'text-slate-400' => ! $row['highlight'], 'text-orange-400' => $row['highlight']])>{{ $row['price'] }}</span>
                                <span class="block text-[10px] text-slate-500">{{ $row['unit'] }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-[11px] leading-4 text-slate-400">{{ __('Freelance and agency figures are typical market rates, shown for comparison only.') }}</p>

                {{-- Published, attributable research — never an invented
                     statistic on a page that sells something. --}}
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['4.5×', __('more leads from companies publishing 16+ posts a month than those publishing four or fewer.'), __('Source: HubSpot blogging benchmark')],
                        ['25%', __('predicted drop in traditional search volume by 2026 as people ask AI assistants instead.'), __('Source: Gartner, 2024')],
                    ] as [$figure, $claim, $source])
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-2xl font-extrabold tracking-tight text-orange-600">{{ $figure }}</p>
                            <p class="mt-1 text-[11px] leading-4 text-slate-600">{{ $claim }}</p>
                            <p class="mt-2 text-[10px] text-slate-400">{{ $source }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>
