<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('content.index') }}" class="text-sm text-slate-500 hover:text-orange-600 dark:text-slate-400">&larr; {{ __('Back to calendar') }}</a>
            <h1 class="mt-1 truncate text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $topic?->title }}</h1>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                {{ $topic?->target_keyword }}
                @if ($topic?->scheduled_for) · {{ __('planned for :date', ['date' => $topic->scheduled_for->translatedFormat('M j, Y')]) }} @endif
            </p>
        </div>
        @if ($presentation)
            <span class="rounded-full bg-{{ $presentation['color'] }}-100 px-2.5 py-1 text-xs font-semibold text-{{ $presentation['color'] }}-700">{{ $presentation['label'] }}</span>
        @endif
    </div>

    @if ($article === null)
        <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('This article has not been written yet.') }}
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- ── Quality panel ────────────────────────────────────── --}}
            <div class="space-y-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center gap-4">
                        @include('reports.charts.ring', [
                            'value' => (float) ($article->seo_score ?? 0),
                            'display' => (int) ($article->seo_score ?? 0),
                            'label' => __('Content quality'),
                            'color' => ($article->seo_score ?? 0) >= 85 ? '#059669' : (($article->seo_score ?? 0) >= 60 ? '#F26419' : '#e11d48'),
                            'size' => 84,
                        ])
                        <div>
                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Content quality') }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ __(':words words', ['words' => number_format($article->word_count)]) }} · {{ __('draft :v', ['v' => $article->version]) }}</div>
                        </div>
                    </div>

                    @if ($issueLabels->isEmpty())
                        <p class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            {{ __('All quality checks passed.') }}
                        </p>
                    @else
                        <div class="mt-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Could be even better') }}</div>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($issueLabels as $label)
                                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400"></span>{{ $label }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Search preview') }}</div>
                    <div class="mt-3 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <div class="truncate text-sm font-medium text-blue-700 dark:text-blue-400">{{ $article->meta_title }}</div>
                        <div class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-500">{{ $topic->website?->domain }}/{{ $article->slug }}</div>
                        <div class="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{{ $article->meta_description }}</div>
                    </div>
                </div>

                <div class="space-y-2">
                    @if ($topic->status === \App\Models\ContentTopic::STATUS_READY)
                        <button wire:click="approve" class="w-full rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-orange-700">
                            {{ __('Approve this article') }}
                        </button>
                    @elseif ($topic->status === \App\Models\ContentTopic::STATUS_SCHEDULED)
                        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-center text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{{ __('Approved and ready to go.') }}</p>
                    @endif
                    <button wire:click="requestNewDraft" wire:confirm="{{ __('Write a completely new draft for this topic?') }}"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        {{ __('Request a new draft') }}
                    </button>
                </div>
            </div>

            {{-- ── Article preview ──────────────────────────────────── --}}
            <div class="lg:col-span-2">
                <article class="ca-preview prose prose-slate max-w-none rounded-xl border border-slate-200 bg-white p-6 sm:p-8 dark:border-slate-800 dark:bg-slate-900 dark:prose-invert">
                    <h1>{{ $article->h1 }}</h1>
                    {!! $previewHtml !!}
                </article>
            </div>

            {{-- Table-of-contents styling (the TOC ships inside the article
                 HTML as <nav class="content-toc">; scoped so it never bleeds). --}}
            <style>
                .ca-preview { scroll-behavior: smooth; }
                .ca-preview .content-toc { margin: 0 0 1.75rem; padding: 1rem 1.25rem; border: 1px solid #e2e8f0; border-radius: 0.75rem; background: #f8fafc; }
                .ca-preview .content-toc__title { margin: 0 0 .5rem; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
                .ca-preview .content-toc ul { margin: 0; padding: 0; list-style: none; }
                .ca-preview .content-toc__item { margin: .25rem 0; }
                .ca-preview .content-toc__item--sub { margin-inline-start: 1rem; font-size: .9em; }
                .ca-preview .content-toc a { color: #c2410c; text-decoration: none; }
                .ca-preview .content-toc a:hover { text-decoration: underline; }
                .dark .ca-preview .content-toc { border-color: #334155; background: #0f172a; }
                .dark .ca-preview .content-toc__title { color: #94a3b8; }
                .dark .ca-preview .content-toc a { color: #fb923c; }
            </style>
        </div>
    @endif
</div>
