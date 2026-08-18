<x-layouts.app>
    @push('styles')
        <style>
            .admin-article h2 { font-size: 1.25rem; font-weight: 700; margin: 1.25rem 0 .5rem; }
            .admin-article h3 { font-size: 1.05rem; font-weight: 700; margin: 1rem 0 .35rem; }
            .admin-article p { margin: .6rem 0; line-height: 1.7; }
            .admin-article ul { list-style: disc; padding-inline-start: 1.25rem; margin: .5rem 0; }
            .admin-article ol { list-style: decimal; padding-inline-start: 1.25rem; margin: .5rem 0; }
            .admin-article a { color: #C44E0E; text-decoration: underline; }
            .admin-article img { border-radius: .75rem; margin: .75rem 0; max-width: 100%; height: auto; }
            .admin-article table { width: 100%; border-collapse: collapse; margin: .75rem 0; }
            .admin-article td, .admin-article th { border: 1px solid #E2E8F0; padding: .4rem .6rem; }
            .admin-article blockquote { border-inline-start: 3px solid #E2E8F0; padding-inline-start: .75rem; }
        </style>
    @endpush

    <div class="mx-auto w-full max-w-5xl space-y-5">
        {{-- Header stacks on a phone; the meta wraps rather than truncating. --}}
        <div>
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.dashboard') }}"
               class="text-sm text-slate-500 hover:text-orange-600 dark:text-slate-400">&larr; Back</a>
            <h1 class="mt-1 text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $topic->title }}</h1>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                @if ($topic->website?->user)
                    <a href="{{ route('admin.clients.show', $topic->website->user) }}" class="font-medium text-orange-600 hover:underline">{{ $topic->website->user->email }}</a>
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                @endif
                <span>{{ $topic->website?->domain ?? '—' }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $topic->status }}</span>
                @if ($topic->scheduled_for)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span>planned {{ $topic->scheduled_for->format('M j, Y') }}</span>
                @endif
            </div>
        </div>

        {{-- What the client said. Shown ABOVE the article: this page is usually
             reached from a complaint, so the complaint is the context. --}}
        @if ($feedback->isNotEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Client feedback</p>
                <ul class="mt-2 space-y-2">
                    @foreach ($feedback as $f)
                        @php
                            $tone = match ($f->rating) {
                                \App\Models\ContentArticleFeedback::RATING_WRONG => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
                                \App\Models\ContentArticleFeedback::RATING_REWRITES => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                                default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                            };
                        @endphp
                        <li class="text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $tone }}">{{ \App\Models\ContentArticleFeedback::label($f->rating) }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ $f->user?->email }} · {{ $f->created_at?->diffForHumans() }}</span>
                                @if ($f->seen_at)
                                    <span class="text-xs text-slate-400">seen by {{ $f->seen_by ?: 'the team' }}</span>
                                @endif
                            </div>
                            @if (trim((string) $f->comment) !== '')
                                <p class="mt-1 text-slate-700 dark:text-slate-200">“{{ $f->comment }}”</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($article === null)
            <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">
                No article has been written for this topic yet.
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['SEO score', $article->seo_score !== null ? $article->seo_score.'/100' : '—'],
                    ['Words', $article->word_count ? number_format($article->word_count) : '—'],
                    ['Version', 'v'.$article->version.' of '.$versions->count()],
                ] as [$label, $value])
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Images with the prompt that made them. This is the fastest way to
                 answer "why is a competitor's name in this picture" — the answer
                 is usually visible in the prompt. --}}
            @if ($images->isNotEmpty())
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Images ({{ $images->count() }})</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach ($images as $img)
                            <div class="min-w-0">
                                <img src="{{ $img->url() }}" alt="{{ $img->alt_text }}" loading="lazy"
                                     class="w-full rounded-lg border border-slate-200 dark:border-slate-800">
                                <p class="mt-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $img->role }}</p>
                                <p class="mt-0.5 break-words text-xs text-slate-500 dark:text-slate-400">{{ $img->prompt }}</p>
                                @if ($img->negative_prompt)
                                    <p class="mt-0.5 break-words text-[11px] text-slate-400">negative: {{ \Illuminate\Support\Str::limit($img->negative_prompt, 140) }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ $article->h1 }}</h2>
                {{-- Allow-list sanitized in the controller (HtmlSanitizer::article) —
                     clients can edit this HTML, so it is not trusted here. --}}
                <div class="admin-article mt-3 text-sm text-slate-800 dark:text-slate-200">{!! $bodyHtml !!}</div>
            </div>
        @endif
    </div>
</x-layouts.app>
