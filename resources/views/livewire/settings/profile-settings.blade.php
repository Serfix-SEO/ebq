<div class="space-y-5">
    {{-- Profile --}}
    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Profile Information') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Update your name, email, and how dates and times are shown.') }}</p>
        </div>
        <form wire:submit="updateProfile" class="space-y-3 px-5 py-4">
            <div>
                <label for="name" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Name') }}</label>
                <input wire:model="name" id="name" type="text"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                @error('name') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Email') }}</label>
                <input wire:model="email" id="email" type="email"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                @error('email') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="timezone" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Time zone') }}</label>
                <select wire:model="timezone" id="timezone"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                    @foreach ($timezoneGroups as $region => $ids)
                        <optgroup label="{{ $region }}">
                            @foreach ($ids as $id)
                                <option value="{{ $id }}">{{ $id }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('timezone') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="inline-flex h-8 items-center rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">{{ __('Save Changes') }}</button>
                @if ($profileSaved)
                    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400" wire:transition>{{ __('Saved') }}</span>
                @endif
            </div>
        </form>
    </div>

    {{-- Password --}}
    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Update Password') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Ensure your account uses a strong password.') }}</p>
        </div>
        <form wire:submit="updatePassword" class="space-y-3 px-5 py-4">
            <div>
                <label for="current_password" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Current Password') }}</label>
                <input wire:model="current_password" id="current_password" type="password"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                @error('current_password') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('New Password') }}</label>
                <input wire:model="password" id="password" type="password"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                @error('password') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Confirm Password') }}</label>
                <input wire:model="password_confirmation" id="password_confirmation" type="password"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
            </div>
            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="inline-flex h-8 items-center rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">{{ __('Update Password') }}</button>
                @if ($passwordSaved)
                    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400" wire:transition>{{ __('Updated') }}</span>
                @endif
            </div>
        </form>
    </div>
</div>
