<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold">Report branding</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Your logo and company details on every report email and the attached PDF.
            </p>
        </div>
    </div>

    @if (! $allowed)
        {{-- Plan gate: show the upgrade banner. Saved rows in the DB are
             preserved; re-enabling the feature on the plan brings them
             back without losing any configuration. --}}
        <div class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800/60 dark:bg-indigo-900/30">
            <p class="text-sm font-semibold text-indigo-900 dark:text-indigo-200">Upgrade to unlock report whitelabel</p>
            <p class="mt-1 text-xs text-indigo-800 dark:text-indigo-300">
                Your current plan sends reports with EBQ branding. Upgrade to send branded reports with your logo, company details, and a PDF attachment your clients can download.
            </p>
            <a href="{{ route('billing.show') }}" class="mt-3 inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-500">
                View plans
            </a>
        </div>
    @else
        @if ($saved)
            <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400" role="status">
                Branding saved.
            </div>
        @endif

        {{-- Scope toggle: edit the user default or the per-website override.
             Switching scopes reloads the form from the matching row so the
             operator can see saved values for either. --}}
        <div class="mt-4 flex gap-1 rounded-md border border-slate-200 bg-slate-50 p-1 text-xs dark:border-slate-700 dark:bg-slate-800/60">
            <button type="button" wire:click="$set('scope', 'user')"
                @class([
                    'flex-1 rounded px-2 py-1.5 font-semibold transition',
                    'bg-white text-indigo-700 shadow-sm dark:bg-slate-900 dark:text-indigo-300' => $scope === 'user',
                    'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200' => $scope !== 'user',
                ])>Default (all my websites)</button>
            <button type="button" wire:click="$set('scope', 'website')"
                @disabled(! $currentWebsite)
                @class([
                    'flex-1 rounded px-2 py-1.5 font-semibold transition',
                    'bg-white text-indigo-700 shadow-sm dark:bg-slate-900 dark:text-indigo-300' => $scope === 'website',
                    'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200' => $scope !== 'website',
                    'opacity-50 cursor-not-allowed' => ! $currentWebsite,
                ])>Override for {{ $currentWebsite?->domain ?: 'this website' }}</button>
        </div>

        <form wire:submit="save" class="mt-4 space-y-4">
            <div>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Company name</label>
                <input type="text" wire:model="company_name" maxlength="120"
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                @error('company_name') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Logo</label>
                    @if ($current_logo_url)
                        <div class="mt-1 flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800/60">
                            <img src="{{ $current_logo_url }}" alt="logo" class="h-10 max-w-[120px] object-contain">
                            <button type="button" wire:click="removeLogo" class="text-[11px] font-semibold text-red-600 hover:underline">Remove</button>
                        </div>
                    @endif
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/svg+xml"
                        class="mt-1 block w-full text-xs file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 dark:file:bg-slate-800 dark:file:text-slate-200">
                    @error('logo') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Accent color</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" wire:model.live="accent_color" class="h-9 w-12 cursor-pointer rounded border border-slate-300 dark:border-slate-700">
                        <input type="text" wire:model="accent_color" maxlength="7"
                            class="flex-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    </div>
                    @error('accent_color') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Footer text</label>
                <textarea wire:model="footer_text" rows="2" maxlength="2000" placeholder="Confidential — for the addressee only."
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Contact email</label>
                    <input type="email" wire:model="contact_email" maxlength="191"
                        class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    @error('contact_email') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Contact phone</label>
                    <input type="text" wire:model="contact_phone" maxlength="64"
                        class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Address</label>
                <textarea wire:model="contact_address" rows="2" maxlength="2000"
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Reply-to email</label>
                <input type="email" wire:model="reply_to_email" maxlength="191" placeholder="hello@your-agency.com"
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <p class="mt-1 text-[10px] text-slate-500">Replies to the report email go here instead of the sending mailbox.</p>
                @error('reply_to_email') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end">
                <button type="submit"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-500">
                    Save branding
                </button>
            </div>
        </form>
    @endif
</div>
