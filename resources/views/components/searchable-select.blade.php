@props([
    'name',
    'label' => null,
    'options' => [],
    'optionValue' => null,
    'optionLabel' => null,
    'optionData' => [],
    'value' => null,
    'selected' => null,
    'id' => null,
    'placeholder' => '-- Select --',
    'searchPlaceholder' => 'Type to filter...',
    'required' => false,
    'hint' => null,
])

@php
    /*
    |----------------------------------------------------------------------
    | x-searchable-select  -  type-to-filter a long option list
    |----------------------------------------------------------------------
    |
    | This is what replaced select2 (and the jQuery it needed). It takes the
    | same arguments as x-select, so swapping one for the other on a page with a
    | few hundred products or customers is a one-word change:
    |
    |   <x-searchable-select name="supplier_id" label="Supplier" :options="$suppliers"
    |                        option-value="id" option-label="name" :option-data="['cost_price']" />
    |
    | How it works: a real <select> is rendered and kept in the form, carrying
    | the name, the `required`, the option `data-*` attributes and the change
    | event. The listbox above it only moves that select's selection and fires a
    | genuine `change`, so server validation, `old()` repopulation and existing
    | page scripts all keep working. The select is visually hidden rather than
    | display:none because browsers refuse to validate a field they cannot show.
    |
    | Behaviour lives in resources/js/alpine/searchable-select.js.
    |
    | With JavaScript off, the native select is still there and still usable.
    */
    $selectId = $id ?? \Illuminate\Support\Str::slug($name, '_');
    $current = collect(old($name, $value ?? $selected));

    // Normalise whatever was passed in (Eloquent collection, array of arrays,
    // or a plain value => label map) into one shape to render.
    $rows = collect($options)->map(function ($option, $key) use ($optionValue, $optionLabel, $optionData) {
        if (is_object($option) || is_array($option)) {
            $val = $optionValue ? data_get($option, $optionValue) : (data_get($option, 'value') ?? $key);
            $text = $optionLabel ? data_get($option, $optionLabel) : (data_get($option, 'label') ?? $key);
            $data = collect($optionData)
                ->mapWithKeys(fn ($attr) => ['data-' . str_replace('_', '-', $attr) => data_get($option, $attr)])
                ->all();
        } else {
            $val = $key;
            $text = $option;
            $data = [];
        }

        return ['value' => $val, 'label' => $text, 'data' => $data];
    })->values();
@endphp

{{-- Escape is only swallowed while the list is open, so it still closes a modal this field sits in. --}}
<div class="mb-4" x-data="searchableSelect"
     @keydown.escape="if (open) { $event.stopPropagation(); close(); }"
     @click.outside="close(false)">
    @if ($label)
        <label for="{{ $selectId }}_button" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if ($required)
                <span class="text-red-600" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    {{-- The field that actually posts. sr-only, not hidden, so it still validates. --}}
    <select
        x-ref="select"
        id="{{ $selectId }}"
        name="{{ $name }}"
        tabindex="-1"
        aria-hidden="true"
        @if ($required) required @endif
        {{ $attributes->merge(['class' => 'sr-only']) }}
    >
        <option value="">{{ $placeholder }}</option>

        @foreach ($rows as $row)
            <option value="{{ $row['value'] }}"
                    @selected($current->contains($row['value']))
                    @foreach ($row['data'] as $attr => $attrValue) {{ $attr }}="{{ $attrValue }}" @endforeach>
                {{ $row['label'] }}
            </option>
        @endforeach
    </select>

    <div class="relative">
        {{-- min-h-touch: this is tapped with a finger at the till. --}}
        <button
            type="button"
            x-ref="button"
            id="{{ $selectId }}_button"
            @click="toggle()"
            @keydown.down.prevent="show()"
            @keydown.enter.prevent="show()"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="{{ $selectId }}_listbox"
            class="flex min-h-touch w-full items-center justify-between gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-left shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
        >
            <span class="truncate" x-text="label || @js($placeholder)"
                  :class="label ? '' : 'text-gray-400 dark:text-gray-500'"></span>
            <x-icon name="chevron-down" size="4" class="text-gray-400" />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            class="absolute z-modal mt-1 w-full overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-800"
        >
            <div class="border-b border-gray-200 p-2 dark:border-gray-600">
                <input
                    type="search"
                    x-ref="search"
                    x-model="query"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="chooseHighlighted()"
                    @keydown.tab="close(false)"
                    placeholder="{{ $searchPlaceholder }}"
                    aria-label="{{ $searchPlaceholder }}"
                    autocomplete="off"
                    class="w-full rounded border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                >
            </div>

            <ul x-ref="list" id="{{ $selectId }}_listbox" role="listbox"
                class="scroll-thin max-h-60 overflow-y-auto py-1 text-sm">
                <template x-for="(option, i) in filtered" :key="option.i">
                    <li
                        role="option"
                        :aria-selected="isChosen(option) ? 'true' : 'false'"
                        @click="choose(option)"
                        @mouseenter="index = i"
                        class="flex min-h-touch cursor-pointer items-center justify-between gap-2 px-3 py-2"
                        :class="{
                            'bg-primary-50 dark:bg-primary-900/40': index === i,
                            'font-semibold text-primary-700 dark:text-primary-300': isChosen(option),
                            'opacity-40 cursor-not-allowed': option.disabled,
                        }"
                    >
                        <span class="truncate" x-text="option.text"></span>
                        <span x-show="isChosen(option)" class="text-primary-600 dark:text-primary-400">
                            <x-icon name="check-circle" size="4" />
                        </span>
                    </li>
                </template>

                <li x-show="filtered.length === 0"
                    class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">
                    Nothing matches "<span x-text="query"></span>".
                </li>
            </ul>
        </div>
    </div>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
    @enderror
</div>
