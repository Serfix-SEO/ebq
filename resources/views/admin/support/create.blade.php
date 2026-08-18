<x-layouts.app>
    {{-- Start a thread WITH a client. Until now a ticket could only begin with
         the customer, so anything we initiated happened over email — outside
         the thread they can see, reply to, and find again later. --}}
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <div>
            <a href="{{ route('admin.support.index') }}" class="text-sm text-slate-500 hover:text-orange-600 dark:text-slate-400">&larr; Back to tickets</a>
            <h1 class="mt-1 text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Open a ticket with a client</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                They get the message by email and can reply from their Support page. The ticket opens as
                <strong>Answered</strong> — we spoke last, so it stays out of the "awaiting reply" queue until they respond.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.support.store') }}"
            class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @csrf

            <div>
                <label for="user_id" class="block text-sm font-semibold text-slate-700 dark:text-slate-200">Client</label>
                <select id="user_id" name="user_id" required
                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                    <option value="">Choose a client…</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client['id'] }}" @selected(old('user_id', $selectedUserId) === $client['id'])>{{ $client['label'] }}</option>
                    @endforeach
                </select>
                @error('user_id') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-slate-400">
                    Newest first. Lead placeholders from the public funnel are excluded — they have no real address to write to.
                </p>
            </div>

            <div>
                <label for="subject" class="block text-sm font-semibold text-slate-700 dark:text-slate-200">Subject</label>
                <input id="subject" name="subject" type="text" required maxlength="200" value="{{ old('subject') }}"
                    placeholder="What this is about"
                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                @error('subject') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">Message</label>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Shown to the client in-app and emailed verbatim — write it for them.</p>
                <div class="mt-2">
                    <x-support.html-editor name="body" placeholder="Write your message…" />
                </div>
                @error('body') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('admin.support.index') }}"
                    class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</a>
                <button type="submit"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    Send &amp; open ticket
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
