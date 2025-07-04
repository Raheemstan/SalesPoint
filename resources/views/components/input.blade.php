<div class="mb-4">
    <label for="{{ $id ?? $name }}" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
        {{ $label ?? ucfirst($name) }}
    </label>
    <input id="{{ $id ?? $name }}" name="{{ $name }}" type="{{ $type ?? 'text' }}"
        value="{{ old($name, $value ?? '') }}" {{ $attributes->merge([
    'class' => 'w-full px-3 py-2 border rounded-md shadow-sm focus:ring focus:ring-blue-200 dark:bg-gray-800 dark:text-white dark:border-gray-600'
]) }} />
    @error($name)
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror
</div>