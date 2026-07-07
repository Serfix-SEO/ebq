<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold">{{ __('Report email transport') }}</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ __('Where report emails get sent from — your Gmail, Outlook, an SMTP server, or the Serfix default.') }}
            </p>
        </div>
    </div>

    @if (! $allowed)
        <div class="mt-4 rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-800/60 dark:bg-orange-900/30">
            <p class="text-sm font-semibold text-orange-900 dark:text-orange-200">{{ __('Upgrade to send from your own mailbox') }}</p>
            <p class="mt-1 text-xs text-orange-800 dark:text-orange-300">
                {{ __('On your current plan, reports send from Serfix\'s default mailer. Upgrade to send reports from your own Gmail or Outlook account (better deliverability), or to plug in a custom SMTP server.') }}
            </p>
            <a href="{{ route('billing.show') }}" class="mt-3 inline-flex items-center rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500">{{ __('View plans') }}</a>
        </div>
    @else
        @if ($saved)
            <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400" role="status">
                {{ __('Transport saved.') }}
            </div>
        @endif
        @if ($testResult)
            <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                {{ $testResult }}
            </div>
        @endif

        <div class="mt-4 flex gap-1 rounded-md border border-slate-200 bg-slate-50 p-1 text-xs dark:border-slate-700 dark:bg-slate-800/60">
            <button type="button" wire:click="$set('scope', 'user')"
                @class([
                    'flex-1 rounded px-2 py-1.5 font-semibold transition',
                    'bg-white text-orange-700 shadow-sm dark:bg-slate-900 dark:text-orange-300' => $scope === 'user',
                    'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200' => $scope !== 'user',
                ])>{{ __('Default (all my websites)') }}</button>
            <button type="button" wire:click="$set('scope', 'website')" @disabled(! $currentWebsite)
                @class([
                    'flex-1 rounded px-2 py-1.5 font-semibold transition',
                    'bg-white text-orange-700 shadow-sm dark:bg-slate-900 dark:text-orange-300' => $scope === 'website',
                    'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200' => $scope !== 'website',
                    'opacity-50 cursor-not-allowed' => ! $currentWebsite,
                ])>{{ __('Override for :website', ['website' => $currentWebsite?->domain ?: __('this website')]) }}</button>
        </div>

        <form wire:submit="save" class="mt-4 space-y-4">
            {{-- Provider radio cards --}}
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {{-- Gmail and Outlook are hidden for now; the platform defaults
                     to the Serfix mailer with Custom SMTP as the only override.
                     Re-add the 'gmail'/'outlook' entries here to restore them. --}}
                @php $providers = [
                    ''        => [__('Serfix default'), __('Send from Serfix\'s default mailer. No branding sender, no setup.')],
                    'smtp'    => [__('Custom SMTP'), __('Connect to any SMTP server (Mailgun, Postmark, your own MX).')],
                ]; @endphp
                @foreach ($providers as $key => [$label, $help])
                    <label @class([
                        'flex cursor-pointer flex-col gap-1 rounded-lg border p-3 text-xs transition',
                        'border-orange-500 ring-2 ring-orange-200 dark:border-orange-400 dark:ring-orange-900/40' => $provider === $key,
                        'border-slate-200 hover:border-slate-300 dark:border-slate-700' => $provider !== $key,
                    ])>
                        <span class="flex items-center gap-2">
                            <input type="radio" wire:model.live="provider" value="{{ $key }}" class="h-3.5 w-3.5 text-orange-600">
                            <span class="font-semibold text-slate-800 dark:text-slate-100">{{ $label }}</span>
                        </span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ $help }}</span>
                    </label>
                @endforeach
            </div>

            @if ($provider !== '')
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('From address') }}</label>
                        <input type="email" wire:model="from_address" maxlength="191"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        @if ($provider === 'gmail' || $provider === 'outlook')
                            <p class="mt-1 text-[10px] text-slate-500">{{ __('Must match the connected mailbox. Gmail/Outlook will rewrite it otherwise.') }}</p>
                        @endif
                        @error('from_address') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Display name') }}</label>
                        <input type="text" wire:model="display_name" maxlength="120"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    </div>
                </div>
            @endif

            {{-- Gmail picker --}}
            @if ($provider === 'gmail')
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
                    @if ($googleAccounts->isEmpty())
                        <p class="text-xs text-slate-600 dark:text-slate-300">{{ __('No Google account connected yet, or the connected account is missing the Gmail send scope.') }}</p>
                        <a href="{{ route('google.mail.redirect') }}" class="mt-2 inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-700">{{ __('Connect Gmail') }}</a>
                    @else
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Pick the Google account to send from') }}</label>
                        <select wire:model="oauth_account_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">{{ __('— pick one —') }}</option>
                            @foreach ($googleAccounts as $acct)
                                <option value="{{ $acct->id }}">{{ __('Google account #:id', ['id' => $acct->id]) }}</option>
                            @endforeach
                        </select>
                        <a href="{{ route('google.mail.redirect') }}" class="mt-2 inline-block text-[11px] font-semibold text-orange-600 hover:underline">{{ __('Reconnect with Gmail-send scope →') }}</a>
                    @endif
                </div>
            @endif

            {{-- Outlook picker --}}
            @if ($provider === 'outlook')
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
                    @if ($microsoftAccounts->isEmpty())
                        <p class="text-xs text-slate-600 dark:text-slate-300">{{ __('No Microsoft account connected yet.') }}</p>
                        <a href="{{ route('microsoft.redirect') }}" class="mt-2 inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-700">{{ __('Connect Outlook') }}</a>
                    @else
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Pick the Microsoft account to send from') }}</label>
                        <select wire:model="oauth_account_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">{{ __('— pick one —') }}</option>
                            @foreach ($microsoftAccounts as $acct)
                                <option value="{{ $acct->id }}">{{ $acct->email }}</option>
                            @endforeach
                        </select>
                        <a href="{{ route('microsoft.redirect') }}" class="mt-2 inline-block text-[11px] font-semibold text-orange-600 hover:underline">{{ __('Connect another Outlook account →') }}</a>
                    @endif
                </div>
            @endif

            {{-- SMTP form --}}
            @if ($provider === 'smtp')
                <div class="grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('SMTP host') }}</label>
                        <input type="text" wire:model="smtp_host" maxlength="191" placeholder="smtp.example.com"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        @error('smtp_host') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Port') }}</label>
                        <input type="number" wire:model="smtp_port" min="1" max="65535"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Username') }}</label>
                        <input type="text" wire:model="smtp_username" maxlength="191"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Password') }}</label>
                        <input type="password" wire:model="smtp_password" maxlength="191" placeholder="{{ $savedRow?->smtp_password ? '••••••••' : '' }}"
                            class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <p class="mt-1 text-[10px] text-slate-500">{{ __('Leave blank to keep the stored password.') }}</p>
                    </div>
                    <div class="col-span-2">
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Encryption') }}</label>
                        <select wire:model="smtp_encryption" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            <option value="tls">{{ __('STARTTLS (recommended)') }}</option>
                            <option value="ssl">{{ __('SSL / TLS') }}</option>
                            <option value="none">{{ __('None (insecure — use only behind a private network)') }}</option>
                        </select>
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-between">
                @if ($savedRow)
                    <button type="button" wire:click="sendTest" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ __('Send test email to me') }}
                    </button>
                @else
                    <span></span>
                @endif
                <button type="submit"
                    class="inline-flex items-center rounded-md bg-orange-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500">
                    {{ __('Save transport') }}
                </button>
            </div>

            @if ($savedRow?->last_error)
                <div class="rounded-md border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/30 dark:text-amber-200">
                    <strong>{{ __('Last send failed:') }}</strong> {{ \Illuminate\Support\Str::limit($savedRow->last_error, 280) }}
                </div>
            @elseif ($savedRow?->last_verified_at)
                <p class="text-[10px] text-slate-500">{{ __('Last verified :time.', ['time' => $savedRow->last_verified_at->diffForHumans()]) }}</p>
            @endif
        </form>
    @endif
</div>
