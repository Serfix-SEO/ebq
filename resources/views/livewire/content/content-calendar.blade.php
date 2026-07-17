<div class="space-y-6">
    @if (session('content-status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
            {{ session('content-status') }}
        </div>
    @endif

    @if (! $hasWebsite)
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('Add a website first to start planning content.') }}
        </div>
    @elseif ($plan === null)
        {{-- ── Setup wizard ─────────────────────────────────────────── --}}
        <div class="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-6 flex items-center gap-3">
                @foreach ([1 => __('Your business'), 2 => __('Schedule & style')] as $step => $label)
                    <div class="flex items-center gap-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold {{ $wizardStep >= $step ? 'bg-orange-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $step }}</span>
                        <span class="text-sm font-medium {{ $wizardStep >= $step ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400' }}">{{ $label }}</span>
                    </div>
                    @if ($step === 1)<div class="h-px w-8 bg-slate-200 dark:bg-slate-700"></div>@endif
                @endforeach
            </div>

            @error('plan')
                <p class="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950 dark:text-rose-300">{{ $message }}</p>
            @enderror

            @if ($wizardStep === 1)
                <div @if($analyzing) wire:init="analyzeSite" @endif>
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ __('Tell us about your business') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('We analyzed your website and filled this in for you. Adjust anything so every article fits your business perfectly.') }}</p>

                @if ($analyzing)
                    <div class="mt-5 flex items-center gap-3 rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('Analyzing your website…') }}
                    </div>
                @endif
                </div>

                <label class="mt-5 block text-sm font-medium text-slate-700 dark:text-slate-300" for="ca-desc">{{ __('What does your business do?') }}</label>
                <textarea id="ca-desc" wire:model="businessDescription" rows="4" maxlength="1000"
                    class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                    placeholder="{{ __('Describe your products, services, and who they are for…') }}"></textarea>
                @error('businessDescription') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300" for="ca-sell">{{ __('What you offer') }}</label>
                        <textarea id="ca-sell" wire:model="sellInput" rows="3"
                            class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            placeholder="{{ __('One per line, most important first') }}"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300" for="ca-dontsell">{{ __("What you don't offer") }}</label>
                        <textarea id="ca-dontsell" wire:model="dontSellInput" rows="3"
                            class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            placeholder="{{ __('So we never write about the wrong things') }}"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button wire:click="nextStep" class="rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-700">{{ __('Continue') }}</button>
                </div>
            @else
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ __('Set your publishing rhythm') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('You can change all of this later. New articles always wait for your approval first.') }}</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300" for="ca-perweek">{{ __('Articles per week') }}</label>
                        <select id="ca-perweek" wire:model="articlesPerWeek"
                            class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white pl-3 pr-8 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            @foreach ([1, 2, 3, 5, 7] as $n)
                                <option value="{{ $n }}">{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300" for="ca-length">{{ __('Article length') }}</label>
                        <select id="ca-length" wire:model="articleLength"
                            class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white pl-3 pr-8 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <option value="1500">{{ __(':n words (concise)', ['n' => '1,500']) }}</option>
                            <option value="2000">{{ __(':n words (recommended)', ['n' => '2,000']) }}</option>
                            <option value="2500">{{ __(':n words (detailed)', ['n' => '2,500']) }}</option>
                            <option value="3000">{{ __(':n words (in-depth)', ['n' => '3,000']) }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid gap-2 sm:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="includeTakeaways" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-700" /> {{ __('Key takeaways box') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="includeFaq" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-700" /> {{ __('FAQ section') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="includeToc" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-700" /> {{ __('Table of contents') }}
                    </label>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <button wire:click="backStep" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Back') }}</button>
                    <button wire:click="createPlan" class="rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-700">
                        <span wire:loading.remove wire:target="createPlan">{{ __('Build my content calendar') }}</span>
                        <span wire:loading wire:target="createPlan">{{ __('Building…') }}</span>
                    </button>
                </div>
            @endif
        </div>
    @else
        {{-- ── Calendar ─────────────────────────────────────────────── --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button wire:click="previousMonth" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="{{ __('Previous month') }}">&larr;</button>
                <h2 style="min-width:10rem" class="text-center text-base font-bold text-slate-900 dark:text-slate-100">{{ $monthStart->translatedFormat('F Y') }}</h2>
                <button wire:click="nextMonth" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="{{ __('Next month') }}">&rarr;</button>
            </div>
            <div class="flex items-center gap-2">
                <button wire:click="$set('view', '{{ $view === 'grid' ? 'list' : 'grid' }}')"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    {{ $view === 'grid' ? __('List view') : __('Calendar view') }}
                </button>
                <button wire:click="$toggle('showAddTopic')" class="rounded-lg bg-orange-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-orange-700">+ {{ __('Add topic') }}</button>
                <button wire:click="pauseOrResume"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    {{ $plan->isActive() ? __('Pause') : __('Resume') }}
                </button>
            </div>
        </div>

        {{-- ── Overview KPIs ────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => __('Planned'), 'value' => $stats['planned'], 'color' => 'sky'],
                ['label' => __('In progress'), 'value' => $stats['in_progress'], 'color' => 'amber'],
                ['label' => __('Ready for review'), 'value' => $stats['ready'], 'color' => 'emerald'],
                ['label' => __('Published'), 'value' => $stats['published'], 'color' => 'orange'],
            ] as $kpi)
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-2xl font-bold text-{{ $kpi['color'] }}-600">{{ number_format($kpi['value']) }}</div>
                    <div class="mt-0.5 text-xs font-medium text-slate-500 dark:text-slate-400">{{ $kpi['label'] }}</div>
                </div>
            @endforeach
        </div>

        @if ($stats['from_search'] > 0 || $stats['monthly_searches'] > 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __(':n topics come straight from what your audience already searches for', ['n' => $stats['from_search']]) }}@if ($stats['monthly_searches'] > 0), {{ __('targeting about :v searches every month', ['v' => number_format($stats['monthly_searches'])]) }}@endif.
            </p>
        @endif

        @unless ($plan->isActive())
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                {{ __('Content planning is paused. New articles are not being written.') }}
            </div>
        @endunless

        @if ($showAddTopic)
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400" for="ca-new-title">{{ __('Article title') }}</label>
                        <input id="ca-new-title" wire:model="newTitle" type="text"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                        @error('newTitle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400" for="ca-new-kw">{{ __('Target keyword') }}</label>
                        <input id="ca-new-kw" wire:model="newKeyword" type="text"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                        @error('newKeyword') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-3 flex justify-end gap-2">
                    <button wire:click="$toggle('showAddTopic')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                    <button wire:click="addTopic" class="rounded-lg bg-orange-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-orange-700">{{ __('Add') }}</button>
                </div>
            </div>
        @endif

        @if ($view === 'grid')
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="grid grid-cols-7 border-b border-slate-200 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400" style="min-width:56rem">
                    @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $dow)
                        <div class="px-2 py-2">{{ $dow }}</div>
                    @endforeach
                </div>
                <div class="grid grid-cols-7" style="min-width:56rem">
                    @foreach ($days as $day)
                        @php $dayTopics = $topicsByDate->get($day->toDateString(), collect()); @endphp
                        <div class="border-b border-e border-slate-100 p-1.5 align-top dark:border-slate-800 {{ $day->format('Y-m') !== $month ? 'bg-slate-50 dark:bg-slate-950' : '' }}" style="min-height:6.5rem">
                            <div class="mb-1 text-end text-xs {{ $day->isToday() ? 'font-bold text-orange-600' : 'text-slate-400' }}">{{ $day->day }}</div>
                            @foreach ($dayTopics as $topic)
                                @php $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status); @endphp
                                <a href="{{ $topic->currentArticle ? route('content.review', $topic->id) : '#' }}"
                                   @if(!$topic->currentArticle) onclick="return false" @endif
                                   class="mb-1 block rounded-lg border border-slate-200 bg-white p-1.5 text-start hover:border-orange-300 dark:border-slate-700 dark:bg-slate-800">
                                    <span class="line-clamp-2 text-xs font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</span>
                                    <span class="mt-1 inline-block rounded-full bg-{{ $p['color'] }}-100 px-1.5 py-px text-[10px] font-semibold text-{{ $p['color'] }}-700">{{ $p['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($topics as $topic)
                        @php $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status); @endphp
                        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div class="w-20 shrink-0 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $topic->scheduled_for?->translatedFormat('M j') }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $topic->target_keyword }}@if($topic->keyword_volume) · {{ number_format($topic->keyword_volume) }} {{ __('searches/mo') }}@endif</div>
                            </div>
                            <span class="rounded-full bg-{{ $p['color'] }}-100 px-2 py-0.5 text-xs font-semibold text-{{ $p['color'] }}-700">{{ $p['label'] }}</span>
                            @if ($topic->currentArticle)
                                <a href="{{ route('content.review', $topic->id) }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">{{ __('Review') }}</a>
                            @endif
                            @if ($topic->status === \App\Models\ContentTopic::STATUS_SUGGESTED)
                                <button wire:click="approve('{{ $topic->id }}')" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">{{ __('Approve') }}</button>
                            @endif
                            @if ($topic->status === \App\Models\ContentTopic::STATUS_FAILED)
                                <button wire:click="retry('{{ $topic->id }}')" class="text-sm font-medium text-orange-600 hover:text-orange-700">{{ __('Try again') }}</button>
                            @endif
                            @if (in_array($topic->status, ['suggested', 'approved', 'ready'], true))
                                <input type="date" min="{{ now()->addDay()->toDateString() }}" value="{{ $topic->scheduled_for?->toDateString() }}"
                                    wire:change="reschedule('{{ $topic->id }}', $event.target.value)"
                                    class="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300" />
                                <button wire:click="skip('{{ $topic->id }}')" class="text-sm text-slate-400 hover:text-slate-600">{{ __('Skip') }}</button>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            {{ __('No articles planned this month yet.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- ── Insight: what your audience searches ─────────────────── --}}
        @if (! empty($audience))
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What your audience is searching for') }}</h3>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Real search demand behind your planned articles.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($audience as $row)
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            {{ $row['keyword'] }}
                            @if ($row['volume'])<span class="text-xs font-semibold text-orange-600">{{ number_format($row['volume']) }}/mo</span>@endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Content strategy map (topic clusters) ────────────────── --}}
        @if (! empty($clusters))
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Content strategy map') }}</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('How your articles group into content pillars around your site.') }}</p>

                @php
                    $cx = 470; $cy = 330; $rings = 185;
                    $n = count($clusters);
                    $palette = ['#F26419', '#0EA5E9', '#10B981', '#8B5CF6', '#EF4444', '#F59E0B', '#64748B'];
                    $statusColor = ['ready' => '#10B981', 'scheduled' => '#10B981', 'published' => '#059669',
                        'writing' => '#F59E0B', 'researching' => '#F59E0B', 'scoring' => '#F59E0B', 'revising' => '#F59E0B',
                        'failed' => '#EF4444', 'approved' => '#0EA5E9', 'suggested' => '#94A3B8'];
                    $siteLabel = \Illuminate\Support\Str::of((string) ($plan->website?->domain ?? 'Site'))
                        ->before('.')->limit(10, '…')->value();
                @endphp
                <div class="mt-3 overflow-x-auto">
                    <svg viewBox="0 0 940 660" class="w-full" style="min-width:660px" role="img" aria-label="{{ __('Content strategy map') }}">
                        @foreach ($clusters as $ci => $cluster)
                            @php
                                $ang = $n <= 1 ? -M_PI/2 : (2*M_PI*$ci/$n) - M_PI/2;
                                $px = $cx + $rings * cos($ang); $py = $cy + $rings * sin($ang);
                                $color = $palette[$ci % count($palette)];
                                // Point labels outward (away from center) on the correct side.
                                $rightSide = $px >= $cx;
                                $shown = array_slice($cluster['topics'], 0, 5);
                                $m = count($shown);
                                $extra = count($cluster['topics']) - $m;
                            @endphp
                            <line x1="{{ round($cx,1) }}" y1="{{ round($cy,1) }}" x2="{{ round($px,1) }}" y2="{{ round($py,1) }}" stroke="{{ $color }}" stroke-width="2" opacity="0.45"/>
                            @foreach ($shown as $li => $topic)
                                @php
                                    // Fan leaves along a vertical-ish comb on the outward side.
                                    $step = 26; $ly = $py + ($li - ($m-1)/2) * $step;
                                    $lx = $px + ($rightSide ? 46 : -46);
                                    $sc = $statusColor[$topic['status']] ?? '#94A3B8';
                                    $anchor = $rightSide ? 'start' : 'end';
                                    $tx = $lx + ($rightSide ? 9 : -9);
                                @endphp
                                <path d="M {{ round($px,1) }} {{ round($py,1) }} C {{ round(($px+$lx)/2,1) }} {{ round($py,1) }}, {{ round(($px+$lx)/2,1) }} {{ round($ly,1) }}, {{ round($lx,1) }} {{ round($ly,1) }}" fill="none" stroke="{{ $color }}" stroke-width="1.2" opacity="0.35"/>
                                <circle cx="{{ round($lx,1) }}" cy="{{ round($ly,1) }}" r="5" fill="{{ $sc }}"/>
                                <text x="{{ round($tx,1) }}" y="{{ round($ly+3.5,1) }}" font-size="11" fill="#64748b" text-anchor="{{ $anchor }}">{{ \Illuminate\Support\Str::limit($topic['title'], 30) }}</text>
                            @endforeach
                            @if ($extra > 0)
                                <text x="{{ round($px + ($rightSide ? 55 : -55),1) }}" y="{{ round($py + ($m/2)*26 + 12,1) }}" font-size="10" fill="#94a3b8" text-anchor="{{ $rightSide ? 'start' : 'end' }}">+{{ $extra }} {{ __('more') }}</text>
                            @endif
                            <circle cx="{{ round($px,1) }}" cy="{{ round($py,1) }}" r="9" fill="{{ $color }}"/>
                            <text x="{{ round($px,1) }}" y="{{ round($py - 15,1) }}" font-size="13" font-weight="700" fill="{{ $color }}" text-anchor="middle">{{ $cluster['theme'] }}</text>
                        @endforeach
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="42" fill="#F26419"/>
                        <text x="{{ $cx }}" y="{{ $cy - 1 }}" font-size="11" font-weight="700" fill="#fff" text-anchor="middle">{{ $siteLabel }}</text>
                        <text x="{{ $cx }}" y="{{ $cy + 14 }}" font-size="9" fill="#fff" text-anchor="middle" opacity="0.85">{{ __('your content') }}</text>
                    </svg>
                </div>
            </div>
        @endif
    @endif
</div>
