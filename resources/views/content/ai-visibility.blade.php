<x-layouts.app :title="__('AI Visibility')">
    {{-- AI Visibility (AEO): whether the AI answer engines can read this site,
         whether their crawlers actually came, and whether people arrive from an
         AI answer. Everything on this page is first-party or from data the
         client already connected — nothing is inferred from a vendor's model
         of the internet. --}}
    <div class="space-y-5">
        <div>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ __('AI Visibility') }}</h1>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ __('People increasingly ask an AI instead of searching. This is what we can see about how those engines treat your site.') }}</p>
        </div>
        <livewire:content.ai-visibility />
    </div>
</x-layouts.app>
