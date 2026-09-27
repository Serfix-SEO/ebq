{{-- Who publishes these articles.

     AI assistants cite people and organisations, not domains. Until this form
     existed, every JSON-LD author we emitted was "Organization: <bare domain>"
     — the weakest claim to expertise available, and the one thing most likely
     to keep a well-written article from being quoted.

     Nothing here is invented on the client's behalf: leave the name empty and
     no Person is published and no byline is added. An article attributed to
     someone who does not exist would be worse than one attributed to nobody. --}}
<div class="space-y-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Author') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {{ __('AI answers and search engines both favour content with a real, named expert behind it. Leave blank to publish under your business name only.') }}
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Name') }}</label>
                <input type="text" wire:model="authorName" maxlength="255" placeholder="{{ __('e.g. Sara Malik') }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Role') }}</label>
                <input type="text" wire:model="authorRole" maxlength="160" placeholder="{{ __('e.g. Head Perfumer') }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>
        </div>

        <div class="mt-4">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Short bio') }}</label>
            <textarea wire:model="authorBio" rows="2" maxlength="1000" placeholder="{{ __('One or two sentences about their experience.') }}"
                      class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Qualifications') }}</label>
                <input type="text" wire:model="authorCredentials" maxlength="300" placeholder="{{ __('e.g. IFRA certified, 12 years') }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Photo URL') }}</label>
                <input type="url" wire:model="authorAvatarUrl" maxlength="600" placeholder="https://…"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>
        </div>

        <div class="mt-4">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Profile links') }}</label>
            <textarea wire:model="authorSameAs" rows="2" placeholder="https://www.linkedin.com/in/…&#10;https://x.com/…"
                      class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ __('One per line. These let an AI confirm the person exists elsewhere, which is what makes the byline count.') }}
            </p>
        </div>

        <div class="mt-4 flex items-center justify-between gap-4 rounded-xl border border-slate-100 p-3 dark:border-slate-800">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ __('Show an author box on every article') }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Adds the name, role and bio at the end of the article.') }}</div>
            </div>
            <button type="button" wire:click="$toggle('authorBoxEnabled')" role="switch" aria-checked="{{ $authorBoxEnabled ? 'true' : 'false' }}"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $authorBoxEnabled ? 'bg-orange-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition {{ $authorBoxEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
            </button>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Business details') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {{ __('Published with every article so search and AI engines can connect them to your business rather than a bare domain.') }}
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Registered business name') }}</label>
                <input type="text" wire:model="orgLegalName" maxlength="255" placeholder="{{ __('e.g. Bellavest Trading LLC') }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>
            <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Logo URL') }}</label>
                <input type="url" wire:model="orgLogoUrl" maxlength="600" placeholder="https://…"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            </div>
        </div>

        <div class="mt-4">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Official profiles') }}</label>
            <textarea wire:model="orgSameAs" rows="2" placeholder="https://www.instagram.com/…&#10;https://www.linkedin.com/company/…"
                      class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
        </div>
    </div>
</div>
