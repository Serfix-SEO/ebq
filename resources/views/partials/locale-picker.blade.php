@if ($showLocalePicker ?? false)
    <div x-data="{ open: true }" x-show="open" x-cloak
        class="fixed inset-0 z-[9998] flex items-center justify-center bg-slate-900/50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-slate-900">
            <p class="text-base font-semibold text-slate-900 dark:text-slate-100">Choose your language / اختر لغتك</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('You can change this anytime from the menu.') }}</p>
            <div class="mt-5 grid grid-cols-2 gap-3">
                <a href="{{ route('locale.set', 'en') }}"
                    class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-800 transition hover:border-orange-300 hover:bg-orange-50 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800">
                    English
                </a>
                <a href="{{ route('locale.set', 'ar') }}"
                    class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-800 transition hover:border-orange-300 hover:bg-orange-50 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800">
                    العربية
                </a>
            </div>
        </div>
    </div>
@endif
