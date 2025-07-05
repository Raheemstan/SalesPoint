@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'value' => null,
    'id' => null,
    'optionValue' => null,
    'optionLabel' => null,
    'placeholder' => null,
    'optionData' => [],
])

@php
    $selectId = $id ?? $name;
    $selectedValues = collect(old($name, $value ?? $selected));
@endphp

<div class="mb-4">
    <label for="{{ $selectId }}" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
        {{ $label ?? ucfirst($name) }}
    </label>

    <select id="{{ $selectId }}" name="{{ $name }}"
        {{ $attributes->merge(['class' => 'w-full px-3 py-2 border rounded-md shadow-sm focus:ring focus:ring-blue-200 dark:bg-gray-800 dark:text-white dark:border-gray-600']) }}>

        @if($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach($options as $key => $option)
    @php
        if (is_object($option)) {
            $val = data_get($option, $optionValue);
            $label = data_get($option, $optionLabel);
            $dataAttrs = collect($optionData)->mapWithKeys(function ($attr) use ($option) {
                return ['data-' . str_replace('_', '-', $attr) => data_get($option, $attr)];
            })->all();
        } elseif (is_array($option)) {
            $val = $optionValue ? data_get($option, $optionValue) : $option['value'] ?? $key;
            $label = $optionLabel ? data_get($option, $optionLabel) : $option['label'] ?? $option;
            $dataAttrs = collect($optionData)->mapWithKeys(function ($attr) use ($option) {
                return ['data-' . str_replace('_', '-', $attr) => data_get($option, $attr)];
            })->all();
        } else {
            $val = $key;
            $label = $option;
            $dataAttrs = [];
        }
    @endphp

    <option
        value="{{ $val }}"
        @if($selectedValues->contains($val)) selected @endif
        @foreach($dataAttrs as $attr => $attrVal)
            {{ $attr }}="{{ $attrVal }}"
        @endforeach
    >
        {{ $label }}
    </option>
@endforeach

    </select>

    @error($name)
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror
</div>
