<x-layouts.app>
    @php
        /**
         * @var array<string, list<array{
         *     name: string,
         *     class: string,
         *     description: string,
         *     synopsis: string,
         *     arguments: list<array{name: string, required: bool, array: bool, description: string, default: mixed}>,
         *     options: list<array{name: string, shortcut: ?string, accept_value: bool, is_value_required: bool, description: string, default: mixed}>,
         *     category: string,
         *     schedule: ?string,
         *     destructive: bool,
         *     notes: string,
         *     examples: list<string>
         * }>> $groups
         * @var int $total
         */
        $categoryColor = [
            'Daily sync'        => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'Rank tracker'      => 'bg-orange-50 text-orange-700 border-orange-200',
            'Backlinks'         => 'bg-sky-50 text-sky-700 border-sky-200',
            'GSC backfill'      => 'bg-amber-50 text-amber-700 border-amber-200',
            'Websites'          => 'bg-rose-50 text-rose-700 border-rose-200',
            'WordPress plugin' => 'bg-purple-50 text-purple-700 border-purple-200',
            'Research engine'  => 'bg-teal-50 text-teal-700 border-teal-200',
            'Uncategorised'    => 'bg-slate-50 text-slate-600 border-slate-200',
        ];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Artisan commands</h1>
                <p class="text-sm text-slate-500">
                    Operator reference for every <code class="rounded bg-slate-100 px-1 text-xs">ebq:*</code>
                    command shipped in <code class="rounded bg-slate-100 px-1 text-xs">app/Console/Commands/</code>.
                    Signatures come live from <code class="rounded bg-slate-100 px-1 text-xs">Artisan::all()</code>;
                    notes &amp; schedule are curated in
                    <code class="rounded bg-slate-100 px-1 text-xs">ArtisanCommandsController::CATALOG</code>.
                </p>
            </div>
            <div class="text-xs text-slate-500">{{ $total }} commands</div>
        </div>

        {{-- Category jump bar --}}
        <div class="flex flex-wrap gap-2 rounded border border-slate-200 bg-white p-3">
            @foreach ($groups as $cat => $rows)
                <a href="#cat-{{ \Illuminate\Support\Str::slug($cat) }}"
                   class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold {{ $categoryColor[$cat] ?? $categoryColor['Uncategorised'] }}">
                    {{ $cat }}
                    <span class="text-[10px] opacity-70">{{ count($rows) }}</span>
                </a>
            @endforeach
        </div>

        @foreach ($groups as $cat => $rows)
            <section id="cat-{{ \Illuminate\Support\Str::slug($cat) }}" class="space-y-3">
                <div class="flex items-center gap-3">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ $cat }}</h2>
                    <span class="text-[10px] uppercase tracking-wider text-slate-400">
                        {{ count($rows) }} command{{ count($rows) === 1 ? '' : 's' }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3">
                    @foreach ($rows as $cmd)
                        <article
                            x-data="{ open: false }"
                            @class([
                                'rounded-lg border bg-white shadow-sm',
                                'border-rose-300' => $cmd['destructive'],
                                'border-slate-200' => ! $cmd['destructive'],
                            ])
                        >
                            <header class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <code class="select-all rounded bg-slate-900 px-2 py-1 font-mono text-xs text-emerald-200">
                                            php artisan {{ $cmd['name'] }}
                                        </code>
                                        @if ($cmd['destructive'])
                                            <span class="inline-flex items-center rounded-full border border-rose-300 bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-rose-700">
                                                Destructive
                                            </span>
                                        @endif
                                        @if ($cmd['schedule'])
                                            <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                                {{ $cmd['schedule'] }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold text-slate-500">
                                                Manual only
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-slate-700">{{ $cmd['description'] ?: '—' }}</p>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    class="shrink-0 rounded-md border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                    x-text="open ? 'Hide details' : 'Show details'"
                                >Show details</button>
                            </header>

                            <div x-show="open" x-cloak class="space-y-4 border-t border-slate-100 px-4 py-4">
                                {{-- Notes --}}
                                @if ($cmd['notes'] !== '')
                                    <div>
                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">What it does</h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-700">{{ $cmd['notes'] }}</p>
                                    </div>
                                @endif

                                {{-- Synopsis --}}
                                <div>
                                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Signature</h3>
                                    <pre class="mt-1 overflow-x-auto rounded bg-slate-50 p-3 font-mono text-xs text-slate-800">{{ $cmd['synopsis'] }}</pre>
                                </div>

                                {{-- Arguments --}}
                                @if (! empty($cmd['arguments']))
                                    <div>
                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Arguments</h3>
                                        <table class="mt-1 w-full text-xs">
                                            <thead class="bg-slate-50 text-left">
                                                <tr>
                                                    <th class="px-3 py-1.5 font-semibold">Name</th>
                                                    <th class="px-3 py-1.5 font-semibold">Required</th>
                                                    <th class="px-3 py-1.5 font-semibold">Default</th>
                                                    <th class="px-3 py-1.5 font-semibold">Description</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($cmd['arguments'] as $arg)
                                                    <tr>
                                                        <td class="px-3 py-1.5 font-mono">{{ $arg['name'] }}{{ $arg['array'] ? '[]' : '' }}</td>
                                                        <td class="px-3 py-1.5">{{ $arg['required'] ? 'yes' : 'no' }}</td>
                                                        <td class="px-3 py-1.5 font-mono text-slate-500">
                                                            {{ $arg['default'] === null ? '—' : (is_scalar($arg['default']) ? (string) $arg['default'] : json_encode($arg['default'])) }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-slate-600">{{ $arg['description'] ?: '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                                {{-- Options --}}
                                @if (! empty($cmd['options']))
                                    <div>
                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Options</h3>
                                        <table class="mt-1 w-full text-xs">
                                            <thead class="bg-slate-50 text-left">
                                                <tr>
                                                    <th class="px-3 py-1.5 font-semibold">Flag</th>
                                                    <th class="px-3 py-1.5 font-semibold">Accepts value</th>
                                                    <th class="px-3 py-1.5 font-semibold">Default</th>
                                                    <th class="px-3 py-1.5 font-semibold">Description</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($cmd['options'] as $opt)
                                                    <tr>
                                                        <td class="px-3 py-1.5 font-mono">
                                                            --{{ $opt['name'] }}{{ $opt['shortcut'] ? ' (-'.$opt['shortcut'].')' : '' }}
                                                        </td>
                                                        <td class="px-3 py-1.5">
                                                            {{ $opt['accept_value'] ? ($opt['is_value_required'] ? 'required' : 'optional') : 'no' }}
                                                        </td>
                                                        <td class="px-3 py-1.5 font-mono text-slate-500">
                                                            {{ $opt['default'] === null || $opt['default'] === false ? '—' : (is_scalar($opt['default']) ? (string) $opt['default'] : json_encode($opt['default'])) }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-slate-600">{{ $opt['description'] ?: '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                                {{-- Examples --}}
                                @if (! empty($cmd['examples']))
                                    <div>
                                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Examples</h3>
                                        <div class="mt-1 space-y-1">
                                            @foreach ($cmd['examples'] as $ex)
                                                <code class="block select-all rounded bg-slate-900 px-3 py-2 font-mono text-xs text-emerald-200">{{ $ex }}</code>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Class reference --}}
                                <div class="border-t border-slate-100 pt-2 text-[10px] text-slate-400">
                                    <span class="font-semibold uppercase tracking-wider">Class:</span>
                                    <code class="font-mono">{{ $cmd['class'] }}</code>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Reminder</p>
            <p class="mt-1">
                Schedules are wired in <code class="rounded bg-amber-100 px-1 text-xs">routes/console.php</code>.
                If you change a schedule there, update the matching entry in
                <code class="rounded bg-amber-100 px-1 text-xs">ArtisanCommandsController::CATALOG</code>
                so this page stays accurate.
            </p>
        </div>
    </div>
</x-layouts.app>
