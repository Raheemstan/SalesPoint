{{--
    One difference figure, rendered the same way everywhere it appears.
    Over and short are named in words as well as coloured, because a filed Z
    report is printed in black and white.

        @include('shifts.partials.difference', ['value' => $row['variance']])

    The wording is deliberately on one line per branch: "Over <amount>" is
    asserted as a single string, so a line break between the word and the
    figure would break it.
--}}
@if ($value === null)
    <span class="text-gray-500 dark:text-gray-400">-</span>
@elseif (abs($value) < 0.005)
    <x-badge variant="green" size="sm" dot>Balanced</x-badge>
@else
    <x-badge :variant="$value > 0 ? 'blue' : 'red'" size="sm" dot>{{ $value > 0 ? 'Over' : 'Short' }} {{ money(abs($value)) }}</x-badge>
@endif
