{{-- Store tab: who the shop is. Printed on receipts and shown in the header. --}}
<x-section title="Business information">
    <div class="grid md:grid-cols-2 gap-4">
        <x-input name="store_name" label="Store Name" :value="setting('store_name')" />
        <x-input name="store_phone" label="Phone" :value="setting('store_phone')" />
        <x-input name="store_motto" label="Store Motto" :value="setting('store_motto')" />
        <x-input name="store_email" label="Email" type="email" :value="setting('store_email')" />
        <x-input name="store_address" label="Address" :value="setting('store_address')" />

        {{-- Alpine, so the chosen file is shown before it is uploaded. --}}
        <div x-data="{ preview: '{{ setting('store_logo') ? asset('storage/' . setting('store_logo')) : '' }}' }">
            <label for="store_logo" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Logo</label>
            <input type="file" name="store_logo" id="store_logo" accept="image/jpeg,image/png"
                @change="const f = $event.target.files[0];
                         if (f) { const r = new FileReader(); r.onload = e => preview = e.target.result; r.readAsDataURL(f); }"
                class="w-full text-sm text-gray-800 dark:text-gray-200" />
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">JPG or PNG, up to 5 MB.</p>
            <img x-show="preview" :src="preview" x-cloak alt="Logo preview" class="mt-2 h-16 w-auto object-contain">
            @error('store_logo')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-section>

<x-section title="Appearance">
    <div class="md:w-1/2">
        <x-select name="theme" label="Preferred theme"
            :options="['light' => 'Light', 'dark' => 'Dark', 'system' => 'Follow the device']"
            :value="setting('theme', 'system')" />
        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
            The switcher in the header changes this too, for whoever is signed in.
        </p>
    </div>
</x-section>
