<div class="mb-4 flex items-center space-x-2">
    <input type="checkbox" id="{{ $id ?? $name }}" name="{{ $name }}" value="1" {{ old($name, $checked ?? false) ? 'checked' : '' }} {{ $attributes->merge(['class' => 'form-checkbox h-4 w-4 text-blue-600 transition duration-150 ease-in-out']) }} />
    <label for="{{ $id ?? $name }}" class="text-sm text-gray-700 dark:text-gray-200">
        {{ $label ?? ucfirst($name) }}
    </label>
</div>

@error($name)
    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
@enderror