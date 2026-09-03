{{--
    Medusa connect card + the full installation guide.

    Medusa has no built-in blog, so this integration ships a small "receiver
    kit": ready-made files the client pastes into their Medusa v2 project.
    The files live in resources/snippets/medusa/ (real .ts/.tsx files, loaded
    here at render time) and implement our signed webhook contract at a fixed
    route; MedusaDriver publishes to it.
--}}
@php
    $medusaKit = [
        ['src/modules/serfix-blog/models/post.ts', 'module-post.ts'],
        ['src/modules/serfix-blog/service.ts', 'module-service.ts'],
        ['src/modules/serfix-blog/index.ts', 'module-index.ts'],
        ['src/api/middlewares.ts', 'middlewares.ts'],
        ['src/api/serfix/articles/route.ts', 'route-articles.ts'],
        ['src/api/store/blog/route.ts', 'route-store-list.ts'],
        ['src/api/store/blog/[slug]/route.ts', 'route-store-single.ts'],
    ];
    $medusaStorefront = [
        ['src/app/[countryCode]/(main)/blog/page.tsx', 'storefront-list.tsx'],
        ['src/app/[countryCode]/(main)/blog/[slug]/page.tsx', 'storefront-single.tsx'],
    ];
    $medusaSnippet = fn (string $file) => file_get_contents(resource_path('snippets/medusa/'.$file));
@endphp

<p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">
    {{ __('Medusa stores don\'t include a blog, so we provide one: paste the ready-made files below into your Medusa project once, and your articles will publish (and update) automatically — including a blog page for your storefront.') }}
</p>

<div class="mt-3 grid gap-3 sm:grid-cols-2">
    <div>
        <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('Medusa server URL') }}</label>
        <input type="url" wire:model="medusaUrl" placeholder="https://api.your-store.com"
               class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        @error('medusaUrl') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        <p class="mt-1 text-[11px] text-slate-400">{{ __('The URL of your Medusa BACKEND (where the admin API lives), not your storefront.') }}</p>
    </div>
    <div>
        <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('Signing secret') }}</label>
        <div class="mt-1 flex gap-2">
            <input type="text" wire:model="medusaSecret"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-mono text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <button type="button" wire:click="regenerateMedusaSecret"
                    class="shrink-0 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Regenerate') }}</button>
        </div>
        @error('medusaSecret') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
        <p class="mt-1 text-[11px] text-slate-400">{{ __('This exact value goes into the SERFIX_SECRET environment variable on your Medusa server (step 4 below). Reconnecting? Paste the secret already in your server\'s env instead.') }}</p>
    </div>
</div>

@include('partials.content-connect.post-status')

{{-- ── Full installation guide ── --}}
<div class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-700"
     x-data="{ open: true, shown: null, copied: null,
               copy(id) { const pre = document.getElementById(id); if (! pre) return;
                          navigator.clipboard.writeText(pre.innerText); this.copied = id;
                          setTimeout(() => this.copied = null, 1500); } }">
    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-start">
        <span class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ __('Setup guide: add the Serfix blog to your Medusa store') }}</span>
        <svg class="h-4 w-4 text-slate-400 transition" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
    </button>

    <div x-show="open" x-cloak class="border-t border-slate-100 px-4 pb-4 dark:border-slate-800">
        <p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">
            {{ __('One-time setup, about 10 minutes, done by whoever maintains your Medusa project (Medusa v2). It adds a blog table to your store\'s database, a secure endpoint that only accepts articles signed with your secret, and public blog pages for your storefront.') }}
        </p>

        <ol class="mt-3 list-decimal space-y-4 ps-5 text-xs leading-5 text-slate-600 dark:text-slate-300">
            <li>
                <span class="font-semibold">{{ __('Add these files to your Medusa project') }}</span> —
                {{ __('create each file at the exact path shown (folders too), copying the contents with the button:') }}
                <div class="mt-2 space-y-2">
                    @foreach ($medusaKit as $i => [$path, $file])
                        <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                            <div class="flex items-center justify-between gap-2 bg-slate-50 px-3 py-1.5 dark:bg-slate-800">
                                <button type="button" @click="shown = shown === 'kit{{ $i }}' ? null : 'kit{{ $i }}'" class="min-w-0 flex-1 truncate text-start font-mono text-[11px] font-semibold text-slate-700 dark:text-slate-200">{{ $path }}</button>
                                <button type="button" @click="copy('medusa-kit-{{ $i }}')"
                                        class="shrink-0 rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-white dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                                    <span x-show="copied !== 'medusa-kit-{{ $i }}'">{{ __('Copy') }}</span>
                                    <span x-show="copied === 'medusa-kit-{{ $i }}'" x-cloak class="text-success">{{ __('Copied') }}</span>
                                </button>
                            </div>
                            <pre id="medusa-kit-{{ $i }}" x-show="shown === 'kit{{ $i }}'" x-cloak dir="ltr" class="max-h-64 overflow-auto bg-slate-900 p-3 text-[11px] leading-4 text-slate-100">{{ $medusaSnippet($file) }}</pre>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-[11px] text-slate-400">{{ __('Already have src/api/middlewares.ts? Just add the route entry from our version to your existing routes array.') }}</p>
            </li>
            <li>
                <span class="font-semibold">{{ __('Register the blog module') }}</span> —
                {{ __('in medusa-config.ts, add this entry to the modules array:') }}
                <div class="mt-2 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                    <div class="flex items-center justify-end bg-slate-50 px-3 py-1 dark:bg-slate-800">
                        <button type="button" @click="copy('medusa-config-line')" class="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-white dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                            <span x-show="copied !== 'medusa-config-line'">{{ __('Copy') }}</span>
                            <span x-show="copied === 'medusa-config-line'" x-cloak class="text-success">{{ __('Copied') }}</span>
                        </button>
                    </div>
                    <pre id="medusa-config-line" dir="ltr" class="overflow-auto bg-slate-900 p-3 text-[11px] leading-4 text-slate-100">{ resolve: "./src/modules/serfix-blog" },</pre>
                </div>
            </li>
            <li>
                <span class="font-semibold">{{ __('Create the database table') }}</span> — {{ __('run in your Medusa project:') }}
                <pre dir="ltr" class="mt-2 overflow-auto rounded-xl bg-slate-900 p-3 text-[11px] leading-4 text-slate-100">npx medusa db:generate serfix_blog
npx medusa db:migrate</pre>
            </li>
            <li>
                <span class="font-semibold">{{ __('Set two environment variables') }}</span> — {{ __('on your Medusa server (.env), using the secret from the field above:') }}
                <pre dir="ltr" class="mt-2 overflow-auto rounded-xl bg-slate-900 p-3 text-[11px] leading-4 text-slate-100">SERFIX_SECRET={{ $this->medusaSecret ?: 'paste-the-signing-secret-here' }}
SERFIX_STOREFRONT_URL=https://your-store.com</pre>
                <p class="mt-1 text-[11px] text-slate-400">{{ __('SERFIX_STOREFRONT_URL is optional but recommended — it lets us link, index and rank-track your live articles.') }}</p>
            </li>
            <li>
                <span class="font-semibold">{{ __('Show the blog on your storefront') }}</span> —
                {{ __('using the Medusa Next.js starter? Add these two pages (skip if you\'ll build your own using the /store/blog API):') }}
                <div class="mt-2 space-y-2">
                    @foreach ($medusaStorefront as $i => [$path, $file])
                        <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                            <div class="flex items-center justify-between gap-2 bg-slate-50 px-3 py-1.5 dark:bg-slate-800">
                                <button type="button" @click="shown = shown === 'sf{{ $i }}' ? null : 'sf{{ $i }}'" class="min-w-0 flex-1 truncate text-start font-mono text-[11px] font-semibold text-slate-700 dark:text-slate-200">{{ $path }}</button>
                                <button type="button" @click="copy('medusa-sf-{{ $i }}')"
                                        class="shrink-0 rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-white dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                                    <span x-show="copied !== 'medusa-sf-{{ $i }}'">{{ __('Copy') }}</span>
                                    <span x-show="copied === 'medusa-sf-{{ $i }}'" x-cloak class="text-success">{{ __('Copied') }}</span>
                                </button>
                            </div>
                            <pre id="medusa-sf-{{ $i }}" x-show="shown === 'sf{{ $i }}'" x-cloak dir="ltr" class="max-h-64 overflow-auto bg-slate-900 p-3 text-[11px] leading-4 text-slate-100">{{ $medusaSnippet($file) }}</pre>
                        </div>
                    @endforeach
                </div>
            </li>
            <li>
                <span class="font-semibold">{{ __('Restart Medusa, then click Connect below') }}</span> —
                {{ __('we\'ll send a signed connection test. If it fails: a "route not found" message means the files aren\'t deployed yet; a "signature" message means SERFIX_SECRET doesn\'t match the secret above.') }}
            </li>
        </ol>
    </div>
</div>
