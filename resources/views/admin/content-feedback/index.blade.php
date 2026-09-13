<x-layouts.app>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Content feedback</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Client verdicts on generated articles — watch where clients are unhappy.</p>
        </div>

        {{-- Summary tiles + rating filter --}}
        @php
            $tiles = [
                ['r' => '', 'label' => 'All', 'n' => $total, 'cls' => 'border-slate-200 text-slate-700 dark:border-slate-700 dark:text-slate-200'],
                ['r' => \App\Models\ContentArticleFeedback::RATING_LOVE, 'label' => '❤️ Loved', 'n' => (int) ($counts['love'] ?? 0), 'cls' => 'border-emerald-200 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300'],
                ['r' => \App\Models\ContentArticleFeedback::RATING_REWRITES, 'label' => '✍️ Rewrites', 'n' => (int) ($counts['rewrites'] ?? 0), 'cls' => 'border-orange-200 text-orange-700 dark:border-orange-800 dark:text-orange-300'],
                ['r' => \App\Models\ContentArticleFeedback::RATING_WRONG, 'label' => '😤 Wrong', 'n' => (int) ($counts['wrong'] ?? 0), 'cls' => 'border-rose-200 text-rose-700 dark:border-rose-800 dark:text-rose-300'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($tiles as $t)
                <a href="{{ route('admin.content-feedback.index', $t['r'] !== '' ? ['rating' => $t['r']] : []) }}"
                    @class([
                        'rounded-xl border bg-white p-4 transition hover:shadow-sm dark:bg-slate-900',
                        $t['cls'],
                        'ring-2 ring-orange-500' => $rating === $t['r'],
                    ])>
                    <div class="text-2xl font-extrabold">{{ number_format($t['n']) }}</div>
                    <div class="text-xs font-semibold">{{ $t['label'] }}</div>
                </a>
            @endforeach
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Client</th>
                            <th class="px-4 py-3">Website</th>
                            <th class="px-4 py-3">Article</th>
                            <th class="px-4 py-3">Verdict</th>
                            <th class="px-4 py-3">What they asked for</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($rows as $row)
                            @php
                                $badge = match ($row->rating) {
                                    'love' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
                                    'rewrites' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
                                    'wrong' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
                                    default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                };
                            @endphp
                            <tr class="align-top text-slate-700 dark:text-slate-300">
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ $row->created_at?->diffForHumans() }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900 dark:text-slate-100">{{ $row->user?->name ?: '—' }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ $row->user?->email }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs">{{ $row->website?->domain }}</td>
                                <td class="max-w-xs px-4 py-3">
                                    @if ($row->topic)
                                        {{-- admin.content.article, NOT content.review: the
                                             client route is scoped to accessible websites and
                                             404s for an admin (found 2026-08-18). --}}
                                        <a href="{{ route('admin.content.article', $row->topic->id) }}" class="text-orange-600 hover:underline dark:text-orange-400">{{ \Illuminate\Support\Str::limit($row->topic->title, 70) }}</a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $badge }}">{{ \App\Models\ContentArticleFeedback::label($row->rating) }}</span>
                                </td>
                                {{-- What the client actually asked for.

                                     The note is their original wording; the
                                     prompts below are the instructions that
                                     really reached the writer — which differ
                                     whenever they accepted the enhancer's
                                     sharpened version, and accumulate when
                                     they asked more than once (the note only
                                     keeps the latest). --}}
                                <td class="max-w-sm px-4 py-3 text-xs">
                                    @php $reqs = $rewrites[$row->topic_id.':'.$row->user_id] ?? collect(); @endphp

                                    @if (trim((string) $row->comment) !== '')
                                        <p class="text-slate-600 dark:text-slate-400">“{{ $row->comment }}”</p>
                                    @elseif ($reqs->isEmpty())
                                        <span class="text-slate-400">—</span>
                                    @endif

                                    @foreach ($reqs as $req)
                                        @php
                                            $prompt = trim((string) $req->prompt);
                                            $enhanced = $prompt !== '' && $prompt !== trim((string) $row->comment);
                                        @endphp
                                        <div @class(['mt-2 rounded border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800/60', 'mt-1' => $loop->first && trim((string) $row->comment) === ''])>
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Rewrite {{ $reqs->count() > 1 ? '#'.($loop->iteration) : '' }}</span>
                                                <span @class([
                                                    'rounded px-1.5 py-0.5 text-[10px] font-bold uppercase',
                                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $req->status === 'done',
                                                    'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' => $req->status === 'failed',
                                                    'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => ! in_array($req->status, ['done', 'failed'], true),
                                                ])>{{ $req->status }}</span>
                                                @if ($enhanced)
                                                    <span class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                                                          title="The client accepted the sharpened version, so this is what reached the writer — not their note above.">sharpened</span>
                                                @endif
                                                <span class="text-[10px] text-slate-400">{{ $req->created_at?->diffForHumans() }}</span>
                                            </div>
                                            <p class="mt-1 leading-relaxed text-slate-700 dark:text-slate-300">
                                                {{ $prompt !== '' ? $prompt : 'No instruction — a general quality pass.' }}
                                            </p>
                                        </div>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">No feedback yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $rows->links() }}</div>
    </div>
</x-layouts.app>
