@props(['name', 'label' => null, 'options' => [], 'selected' => null, 'id' => null])

<div class="mb-4">
    <label for="{{ $id ?? $name }}" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
        {{ $label ?? ucfirst($name) }}
    </label>
    <select id="{{ $id ?? $name }}" name="{{ $name }}" {{ $attributes instanceof \Illuminate\View\ComponentAttributeBag
    ? $attributes->merge(['class' => 'w-full px-3 py-2 border rounded-md shadow-sm focus:ring focus:ring-blue-200 dark:bg-gray-800 dark:text-white dark:border-gray-600'])
    : 'class=w-full px-3 py-2 border rounded-md shadow-sm focus:ring focus:ring-blue-200 dark:bg-gray-800 dark:text-white dark:border-gray-600'
        }}>
        @foreach($options as $key => $value)
            <option value="{{ $key }}" @if(collect(old($name, $selected))->contains($key)) selected @endif>
                {{ $value }}
            </option>
        @endforeach
    </select>

    @error($name)
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror
</div>