<x-layouts.app :title="__('Site Health')">
    {{-- The Site Health module (crawl banner + health score + priority issue
         queue) — the same three components the SEO platform's overview health
         tab renders, surfaced inside Content Autopilot since 2026-08-16. The
         crawl data already exists for every content website (the site-profile
         crawl), so this is pure reuse, not a second crawler. --}}
    <div class="space-y-5">
        <div>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ __('Site Health') }}</h1>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ __('What our crawler found on your website — technical problems that hold your articles back, ranked by how much they matter.') }}</p>
        </div>
        <livewire:crawl-banner />
        <livewire:dashboard.site-health-stats />
        <livewire:dashboard.priority-action-queue />
    </div>
</x-layouts.app>
