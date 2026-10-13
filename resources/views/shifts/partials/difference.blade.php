{{--
    One difference figure, rendered the same way everywhere it appears.
    Over and short are named in words as well as coloured, because a filed Z
    report is printed in black and white.

        @include('shifts.partials.difference', ['value' => $row['variance']])
--}}
@if ($value === null)
    <span class="text-gray-500 dark:text-gray-400">-</span>
@elseif (abs($value) < 0.005)
    <span class="text-green-700 dark:text-green-300">Balanced</span>
@else
    <span class="{{ $value > 0 ? 'text-blue-700 dark:text-blue-300' : 'text-red-600 dark:text-red-400' }}">
        {{ $value > 0 ? 'Over' : 'Short' }} {{ money(abs($value)) }}
    </span>
@endif
