@props(['name' => 'body', 'placeholder' => ''])

{{-- Minimal rich-text reply editor: bold, italic, bullet list, link.
     Writes its HTML into a hidden textarea (works for both Livewire
     wire:model and plain form POSTs); server-side HtmlSanitizer is the
     security boundary — this is a convenience layer only. --}}
<div x-data="{
        init() { try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {} },
        sync() {
            const field = this.$refs.field;
            field.value = this.$refs.editor.innerHTML;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        },
        cmd(c, v = null) { this.$refs.editor.focus(); document.execCommand(c, false, v); this.sync(); },
        link() {
            const url = prompt('{{ __('Link URL (https://…)') }}');
            if (url && /^https?:\/\//i.test(url)) this.cmd('createLink', url);
        },
        clear() { this.$refs.editor.innerHTML = ''; this.sync(); }
    }"
    x-on:support-editor-clear.window="clear()">
    <div class="flex items-center gap-1 rounded-t-xl border border-b-0 border-slate-300 bg-slate-50 px-2 py-1.5 dark:border-slate-700 dark:bg-slate-800">
        <button type="button" x-on:click="cmd('bold')" title="{{ __('Bold') }}"
            class="rounded-lg px-2.5 py-1 text-sm font-extrabold text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-700">B</button>
        <button type="button" x-on:click="cmd('italic')" title="{{ __('Italic') }}"
            class="rounded-lg px-2.5 py-1 text-sm font-bold italic text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-700">I</button>
        <button type="button" x-on:click="cmd('insertUnorderedList')" title="{{ __('Bullet list') }}"
            class="rounded-lg px-2.5 py-1 text-sm font-bold text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-700">•≡</button>
        <button type="button" x-on:click="link()" title="{{ __('Link') }}"
            class="rounded-lg px-2.5 py-1 text-sm font-bold text-slate-600 underline hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-700">🔗</button>
    </div>
    <div x-ref="editor" contenteditable="true" x-on:input="sync()" x-on:blur="sync()"
        data-placeholder="{{ $placeholder }}"
        class="ticket-body support-editor min-h-28 w-full rounded-b-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></div>
    <textarea x-ref="field" name="{{ $name }}" class="hidden" {{ $attributes }}></textarea>
</div>
