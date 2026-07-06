<x-layouts.app>
    @php /** @var array $manifest */ @endphp
    <div class="space-y-5" x-data="fleetSlides()">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $manifest['title'] ?? 'Fleet UI E2E Test' }}</h1>
            <p class="text-sm text-slate-500">
                Browser-driven (Laravel Dusk) end-to-end test of the DB-shard fleet and crawl-worker fleet,
                operated entirely through the admin UI — no CLI.
                @if (!empty($manifest['generated_at']))
                    <span class="text-slate-400">Captured {{ $manifest['generated_at'] }}.</span>
                @endif
            </p>
        </div>

        @if (empty($manifest['slides']))
            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                No test run found yet. Run the Dusk fleet test to populate this report.
            </div>
        @else
            {{-- Main viewer --}}
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="relative bg-slate-900">
                    <template x-for="(s, i) in slides" :key="i">
                        <img x-show="i === current" :src="s.url" :alt="s.title"
                             class="w-full select-none" loading="lazy" />
                    </template>
                    <button @click="prev()" class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/80 px-3 py-2 text-sm font-bold shadow hover:bg-white">‹</button>
                    <button @click="next()" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/80 px-3 py-2 text-sm font-bold shadow hover:bg-white">›</button>
                    <div class="absolute bottom-2 right-3 rounded bg-black/60 px-2 py-0.5 text-xs font-medium text-white">
                        <span x-text="current + 1"></span> / <span x-text="slides.length"></span>
                    </div>
                </div>
                <div class="border-t border-slate-100 p-4">
                    <div class="text-sm font-semibold text-slate-800" x-text="slides[current].title"></div>
                    <p class="mt-1 text-sm text-slate-600" x-text="slides[current].desc"></p>
                </div>
            </div>

            {{-- Thumbnail strip --}}
            <div class="flex flex-wrap gap-2">
                <template x-for="(s, i) in slides" :key="'t'+i">
                    <button @click="current = i"
                            class="overflow-hidden rounded border-2 transition"
                            :class="i === current ? 'border-orange-500' : 'border-transparent opacity-70 hover:opacity-100'">
                        <img :src="s.url" class="h-16 w-28 object-cover object-top" loading="lazy" />
                    </button>
                </template>
            </div>

            {{-- Full step list --}}
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-2 text-sm font-semibold">All steps</h2>
                <ol class="space-y-1 text-sm">
                    <template x-for="(s, i) in slides" :key="'l'+i">
                        <li class="flex gap-2">
                            <button @click="current = i" class="font-mono text-xs text-orange-600 hover:underline" x-text="(i+1)+'.'"></button>
                            <div><span class="font-medium" x-text="s.title"></span> — <span class="text-slate-500" x-text="s.desc"></span></div>
                        </li>
                    </template>
                </ol>
            </div>
        @endif
    </div>

    <script>
        function fleetSlides() {
            return {
                current: 0,
                slides: @json($slides ?? []),
                next() { this.current = (this.current + 1) % this.slides.length; },
                prev() { this.current = (this.current - 1 + this.slides.length) % this.slides.length; },
            };
        }
    </script>
</x-layouts.app>
