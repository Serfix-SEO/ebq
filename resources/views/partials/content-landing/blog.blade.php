{{-- Our own blog runs on the very product this page sells, so the freshest
     proof is three real published articles. Hidden entirely when there are
     none — an empty strip would advertise that nothing ships. --}}
@if ($latestPosts->isNotEmpty())
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ __('Latest from the blog') }}</h2>
                    <p class="mt-2 text-sm text-slate-600">{{ __('Every article here was researched, written and published by Content AI Autopilot.') }}</p>
                </div>
                <a href="{{ route('content-ai.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-orange-600 underline-offset-4 hover:underline">
                    {{ __('View all') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($latestPosts as $post)
                    <a href="{{ $post->url() }}" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-lg hover:shadow-orange-600/5">
                        @if ($image = $post->featuredImage())
                            <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $post->title }}" loading="lazy" class="aspect-video w-full object-cover">
                        @else
                            <div class="flex aspect-video w-full items-center justify-center bg-gradient-to-br from-orange-500 to-orange-600">
                                <span class="text-5xl font-extrabold text-white/90">{{ mb_substr($post->title, 0, 1) }}</span>
                            </div>
                        @endif
                        <div class="flex flex-1 flex-col p-6">
                            <h3 class="text-base font-bold tracking-tight text-slate-900">{{ $post->title }}</h3>
                            <p class="mt-2 flex-1 text-sm leading-6 text-slate-600 line-clamp-2">{{ $post->summary() }}</p>
                            <div class="mt-4 flex items-center gap-x-2 text-xs font-medium text-slate-500">
                                @if ($post->published_at)
                                    <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->toFormattedDateString() }}</time>
                                    <span aria-hidden="true">·</span>
                                @endif
                                <span>{{ __(':n min read', ['n' => $post->readingMinutes()]) }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
