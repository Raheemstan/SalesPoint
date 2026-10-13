@php
    $types = config('sectors.types', []);
    $features = config('sectors.features', []);
    $current = (string) setting('business_type', config('sectors.default', 'retail'));

    /*
     * The presets are handed to Alpine so the screen can answer "what would
     * this change?" while the shop is still choosing, rather than after they
     * have saved and reloaded.
     */
    $presets = collect($types)->map(fn ($type) => [
        'label' => $type['label'] ?? '',
        'features' => (object) ($type['features'] ?? []),
    ]);
@endphp

<div x-data="{
    type: '{{ $current }}',
    presets: {{ Js::from($presets) }},
    /** Is a feature on by default for the business type currently selected? */
    preset(name) {
        return !!(this.presets[this.type]?.features?.[name]);
    },
}">

    <x-section title="Business type">
        {{-- What changing this does, before they change it. --}}
        <div class="mb-4 rounded border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900
                    dark:border-blue-800 dark:bg-blue-900/40 dark:text-blue-100">
            <p class="font-semibold mb-1">What this changes</p>
            <p>
                The business type sets the words the app uses - a pharmacy sells
                <em>drugs</em> to <em>patients</em>, a restaurant takes <em>orders</em> from
                <em>guests</em> - and which optional fields and screens appear.
            </p>
            <p class="mt-1">
                <strong>It does not delete anything.</strong> Switching type only hides or shows
                features: your {{ term('product', true) }}, sales and customers stay exactly as they
                are, and switching back brings the screens back with their data intact. Anything you
                have forced on or off below keeps that setting whatever type you pick.
            </p>
        </div>

        <div class="grid md:grid-cols-2 gap-3">
            @foreach ($types as $key => $type)
                <label class="flex gap-3 items-start rounded border p-3 cursor-pointer transition
                              dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
                    :class="type === '{{ $key }}' ? 'border-blue-500 ring-1 ring-blue-500' : ''">
                    <input type="radio" name="business_type" value="{{ $key }}" x-model="type"
                        class="mt-1 h-4 w-4 text-blue-600">
                    <span>
                        <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $type['label'] ?? $key }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $type['description'] ?? '' }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @error('business_type')
            <p class="text-red-500 text-xs mt-2">{{ $message }}</p>
        @enderror
    </x-section>

    <x-section title="Features">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            Every feature follows the business type you picked above unless you say otherwise here.
            Forcing one on or off sticks, so a shop is never boxed in by the preset it chose.
        </p>

        <div class="space-y-2">
            @foreach ($features as $name => $description)
                @php $key = 'feature_' . $name; @endphp
                <div class="grid md:grid-cols-[1fr_14rem] gap-2 md:gap-4 md:items-center
                            border-b border-gray-100 dark:border-gray-700 pb-2 last:border-0">
                    <div>
                        <span class="font-medium text-gray-800 dark:text-gray-100">
                            {{ ucfirst(str_replace('_', ' ', $name)) }}
                        </span>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
                    </div>

                    {{-- Three states, not a checkbox: '' = follow the preset. --}}
                    <div>
                        <select name="{{ $key }}"
                            class="w-full px-3 py-2 border rounded-md shadow-sm text-sm
                                   dark:bg-gray-800 dark:text-white dark:border-gray-600">
                            <option value="" @selected(old($key, setting($key, '')) === '')>Follow the business type</option>
                            <option value="1" @selected(old($key, setting($key, '')) === '1')>Always on</option>
                            <option value="0" @selected(old($key, setting($key, '')) === '0')>Always off</option>
                        </select>
                        {{-- Updates as the type above is changed, so "follow" is never a mystery. --}}
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            The selected type has this <strong x-text="preset('{{ $name }}') ? 'on' : 'off'"></strong>.
                        </p>
                        @error($key)
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>
    </x-section>
</div>
