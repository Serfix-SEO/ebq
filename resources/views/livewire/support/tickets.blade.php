<div class="mx-auto w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Support') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Ask us anything — we reply here and by email.') }}</p>
        </div>
        @if (! $showCreate)
            <button type="button" wire:click="$set('showCreate', true)"
                class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New ticket') }}
            </button>
        @endif
    </div>

    @if ($showCreate)
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What can we help with?') }}</h2>
            <div class="mt-3 space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Subject') }}</label>
                    <input wire:model="subject" type="text" maxlength="200" placeholder="{{ __('e.g. My article did not appear on my website') }}"
                        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    @error('subject') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Message') }}</label>
                    <textarea wire:model="message" rows="5" placeholder="{{ __('Describe what happened, and what you expected instead.') }}"
                        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                    @error('message') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" wire:click="$set('showCreate', false)" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="create" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                        <span wire:loading.remove wire:target="create">{{ __('Send') }}</span>
                        <span wire:loading wire:target="create">{{ __('Sending…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($tickets->isEmpty() && ! $showCreate)
        <div class="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-100 text-orange-600 dark:bg-orange-950 dark:text-orange-300">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
            </span>
            <p class="mt-3 text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('No tickets yet') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Stuck on something? Open a ticket and we\'ll get back to you.') }}</p>
        </div>
    @elseif ($tickets->isNotEmpty())
        <div class="space-y-2.5">
            @foreach ($tickets as $ticket)
                <a href="{{ route('support.show', $ticket->id) }}" wire:key="ticket-{{ $ticket->id }}"
                    class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm transition hover:border-orange-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-800">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ $ticket->subject }}</div>
                        <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ trans_choice(':n message|:n messages', $ticket->messages_count, ['n' => $ticket->messages_count]) }}
                            · {{ __('updated') }} {{ $ticket->last_reply_at?->diffForHumans() ?? $ticket->created_at->diffForHumans() }}
                        </div>
                    </div>
                    @if ($ticket->status === \App\Models\SupportTicket::STATUS_ANSWERED)
                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ __('Replied') }}</span>
                    @elseif ($ticket->status === \App\Models\SupportTicket::STATUS_CLOSED)
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ __('Closed') }}</span>
                    @else
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ __('Open') }}</span>
                    @endif
                </a>
            @endforeach
        </div>
        <div>{{ $tickets->links() }}</div>
    @endif
</div>
