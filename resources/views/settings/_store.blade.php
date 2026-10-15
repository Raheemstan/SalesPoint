@php
    /*
    | Store tab: who the shop is. Printed on receipts and shown in the header.
    |
    | The control classes are written out here rather than taken from x-input:
    | that component still carries the old blue focus ring and prints its own
    | label, and every settings field has to look like every other field in the
    | app (see reports/partials/filter-bar for the same strings).
    */
    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';

    $fields = [
        'store_name' => 'Store name',
        'store_phone' => 'Phone',
        'store_motto' => 'Store motto',
        'store_email' => 'Email',
        'store_address' => 'Address',
    ];
@endphp

<x-card title="Business information" subtitle="Printed on every receipt and shown in the app header.">
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($fields as $name => $text)
            <div>
                <label for="{{ $name }}" class="{{ $label }}">{{ $text }}</label>
                <input type="{{ $name === 'store_email' ? 'email' : 'text' }}"
                       id="{{ $name }}" name="{{ $name }}"
                       value="{{ old($name, setting($name)) }}"
                       class="{{ $control }}">
                @error($name)
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        {{-- Alpine, so the chosen file is shown before it is uploaded.
             The starting value comes from MediaService::url(), which answers
             null when the stored file is not on the disk - so a dangling
             setting shows nothing rather than a broken image. --}}
        <div x-data="{ preview: '{{ app(\App\Services\MediaService::class)->url(setting('store_logo')) ?? '' }}' }">
            <label for="store_logo" class="{{ $label }}">Logo</label>
            <input type="file" name="store_logo" id="store_logo" accept="image/jpeg,image/png,image/webp"
                @change="const f = $event.target.files[0];
                         if (f) { const r = new FileReader(); r.onload = e => preview = e.target.result; r.readAsDataURL(f); }"
                class="block w-full text-sm text-gray-600 dark:text-gray-300
                       file:mr-3 file:min-h-touch file:rounded-md file:border-0 file:bg-primary-600
                       file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white
                       hover:file:bg-primary-700" />
            <p class="{{ $hint }}">JPG, PNG or WebP, up to 1 MB. Shown on screen and printed on receipts.</p>
            <img x-show="preview" :src="preview" x-cloak alt="Logo preview"
                 {{-- x-on:error, not @error: Blade claims @error for its own
                      validation directive, so the Alpine shorthand would be
                      compiled away and the handler would never exist. --}}
                 x-on:error="preview = ''"
                 class="mt-2 h-16 w-auto rounded-md object-contain ring-1 ring-gray-200 dark:ring-gray-700">

            @if (setting('store_logo'))
                <label class="mt-2 inline-flex min-h-touch items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="remove_store_logo" value="1"
                           class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-900">
                    Remove the logo and go back to the default
                </label>
            @endif

            @error('store_logo')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-card>

<x-card title="Appearance" subtitle="Light or dark, for every screen in the app.">
    <div class="sm:max-w-sm">
        <label for="theme" class="{{ $label }}">Preferred theme</label>
        <select id="theme" name="theme" class="{{ $control }}">
            @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'Follow the device'] as $value => $text)
                <option value="{{ $value }}" @selected(old('theme', setting('theme', 'system')) === $value)>
                    {{ $text }}
                </option>
            @endforeach
        </select>
        <p class="{{ $hint }}">
            The switcher in the header changes this too, for whoever is signed in.
        </p>
        @error('theme')
            <p class="{{ $error }}">{{ $message }}</p>
        @enderror
    </div>
</x-card>
