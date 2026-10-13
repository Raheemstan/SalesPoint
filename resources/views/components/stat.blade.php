@props([
    'label',
    'value',
    'delta' => null,
    'trend' => null,
    'invert' => false,
    'hint' => null,
    'icon' => null,
    'href' => null,
])

@php
    /*
    |----------------------------------------------------------------------
    | x-stat  -  a KPI tile
    |----------------------------------------------------------------------
    |
    |   <x-stat label="Sales today" :value="money($today)" />
    |   <x-stat label="Sales today" :value="money($today)" delta="+12%" trend="up" />
    |   <x-stat label="Expenses" :value="money($spent)" delta="+8%" trend="up" invert />
    |
    | `trend` colours the delta: up is green, down is red. Pass `invert` where
    | rising is bad (expenses, shrinkage, refunds) so the colour still means
    | "good" or "bad" rather than merely "up" or "down". Leave `trend` off for a
    | delta that should not be judged either way.
    */
    $good = $invert ? 'down' : 'up';
    $bad = $invert ? 'up' : 'down';

    $deltaClass = match ($trend) {
        $good => 'text-green-700 dark:text-green-400',
        $bad => 'text-red-700 dark:text-red-400',
        default => 'text-gray-500 dark:text-gray-400',
    };

    $trendIcon = match ($trend) {
        'up' => 'trend-up',
        'down' => 'trend-down',
        'flat' => 'minus',
        default => null,
    };

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => 'block rounded-xl bg-white dark:bg-gray-800 p-4 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700'
            . ($href ? ' transition hover:ring-primary-400 dark:hover:ring-primary-500' : ''),
    ]) }}
>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>

        @if ($icon)
            <span class="rounded-lg bg-primary-50 p-1.5 text-primary-600 dark:bg-primary-900/40 dark:text-primary-300">
                <x-icon :name="$icon" size="5" />
            </span>
        @endif
    </div>

    {{-- tabular-nums so a column of figures lines up as it changes. --}}
    <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-gray-100">
        {{ $value }}
    </p>

    @if ($delta !== null || $hint)
        <div class="mt-1.5 flex items-center gap-1.5 text-sm">
            @if ($delta !== null)
                <span class="inline-flex items-center gap-1 font-medium {{ $deltaClass }}">
                    @if ($trendIcon)
                        <x-icon :name="$trendIcon" size="4" />
                    @endif
                    {{ $delta }}
                </span>
            @endif

            @if ($hint)
                <span class="text-gray-500 dark:text-gray-400">{{ $hint }}</span>
            @endif
        </div>
    @endif

    {{ $slot }}
</{{ $tag }}>
