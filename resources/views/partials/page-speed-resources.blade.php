{{-- Offending-resource table for one opportunity/diagnostic. Expects $res
     = ['columns'=>[{label,numeric}], 'rows'=>[[{text,is_url}]], total, truncated]. --}}
@if (! empty($res) && ! empty($res['rows']))
    <div class="mt-2.5 overflow-x-auto rounded-lg border border-slate-100 dark:border-slate-800">
        <table class="w-full text-left text-[11px]">
            <thead class="bg-slate-50 dark:bg-slate-800/50">
                <tr>
                    @foreach ($res['columns'] as $col)
                        <th class="px-2.5 py-1.5 font-semibold text-slate-500 dark:text-slate-400 {{ $col['numeric'] ? 'text-right' : '' }}">{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($res['rows'] as $row)
                    <tr>
                        @foreach ($row as $i => $cell)
                            <td class="px-2.5 py-1.5 align-top text-slate-600 dark:text-slate-300 {{ ($res['columns'][$i]['numeric'] ?? false) ? 'whitespace-nowrap text-right tabular-nums' : '' }}">
                                @if ($cell['is_url'])
                                    <span class="block max-w-[20rem] truncate font-mono text-[10px] text-slate-500 dark:text-slate-400" title="{{ $cell['text'] }}">{{ $cell['text'] }}</span>
                                @else
                                    {{ $cell['text'] }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (! empty($res['truncated']))
            <p class="bg-slate-50 px-2.5 py-1 text-[10px] text-slate-400 dark:bg-slate-800/50 dark:text-slate-500">{{ __('Showing') }} {{ count($res['rows']) }} {{ __('of') }} {{ $res['total'] }} {{ __('resources') }}</p>
        @endif
    </div>
@endif
