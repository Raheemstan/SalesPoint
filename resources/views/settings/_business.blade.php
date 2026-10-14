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

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';

    // The nouns this trade uses, so the warning below talks about the shop's
    // own records rather than about "products" and "customers".
    $productTerm = Str::lower(term('product', true));
    $saleTerm = Str::lower(term('sale', true));
    $customerTerm = Str::lower(term('customer', true));
@endphp

<div x-data="{
    type: '{{ $current }}',
    presets: {{ Js::from($presets) }},
    /** Is a feature on by default for the business type currently selected? */
    preset(name) {
        return !!(this.presets[this.type]?.features?.[name]);
    },
}" class="space-y-4">

    <x-card title="Business type" subtitle="The trade this shop is in, which sets the words and the screens.">
        {{-- What changing this does, before they change it. --}}
        <div class="mb-4 flex items-start gap-3 rounded-lg bg-primary-50 p-3 text-xs text-primary-900
                    ring-1 ring-primary-200 dark:bg-primary-900/30 dark:text-primary-100 dark:ring-primary-800">
            <span class="mt-0.5 shrink-0 text-primary-600 dark:text-primary-300">
                <x-icon name="info" size="5" />
            </span>
            <div class="min-w-0">
                <p class="mb-1 font-semibold">What this changes</p>
                <p>
                    The business type sets the words the app uses - a pharmacy sells
                    <em>drugs</em> to <em>patients</em>, a restaurant takes <em>orders</em> from
                    <em>guests</em> - and which optional fields and screens appear.
                </p>
                <p class="mt-1">
                    <strong>It does not delete anything.</strong> Switching type only hides or shows
                    features: your {{ $productTerm }}, {{ $saleTerm }} and {{ $customerTerm }} stay exactly
                    as they are, and switching back brings the screens back with their data intact.
                    Anything you have forced on or off below keeps that setting whatever type you pick.
                </p>
            </div>
        </div>

        <fieldset>
            <legend class="sr-only">Business type</legend>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($types as $key => $type)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 transition
                                  hover:bg-gray-50 dark:hover:bg-gray-700/50"
                        :class="type === '{{ $key }}'
                            ? 'bg-primary-50 ring-primary-500 dark:bg-primary-900/30 dark:ring-primary-500'
                            : 'ring-gray-200 dark:ring-gray-700'">
                        <input type="radio" name="business_type" value="{{ $key }}" x-model="type"
                            class="mt-0.5 h-5 w-5 shrink-0 border-gray-300 text-primary-600
                                   focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $type['label'] ?? $key }}
                            </span>
                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                {{ $type['description'] ?? '' }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        @error('business_type')
            <p class="{{ $error }}">{{ $message }}</p>
        @enderror
    </x-card>

    <x-card title="Features" subtitle="Each one follows the business type above unless you override it here.">
        <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
            Forcing a feature on or off sticks, so a shop is never boxed in by the preset it chose.
        </p>

        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach ($features as $name => $description)
                @php
                    $key = 'feature_' . $name;
                    $saved = (string) old($key, setting($key, ''));
                    // What the saved type does, so the hint is not blank before Alpine boots.
                    $presetNow = data_get($types, $current . '.features.' . $name, false) ? 'on' : 'off';
                @endphp

                <div class="grid gap-2 py-3 md:grid-cols-[1fr_15rem] md:items-start md:gap-4">
                    <div class="min-w-0">
                        <label for="{{ $key }}" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ ucfirst(str_replace('_', ' ', $name)) }}
                        </label>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
                    </div>

                    {{-- Three states, not a checkbox: '' = follow the preset. --}}
                    <div>
                        <select id="{{ $key }}" name="{{ $key }}" class="{{ $control }}">
                            <option value="" @selected($saved === '')>Follow the business type</option>
                            <option value="1" @selected($saved === '1')>Always on</option>
                            <option value="0" @selected($saved === '0')>Always off</option>
                        </select>

                        {{-- Updates as the type above is changed, so "follow" is never a mystery. --}}
                        <p class="{{ $hint }}">
                            The selected type has this
                            <strong x-text="preset('{{ $name }}') ? 'on' : 'off'">{{ $presetNow }}</strong>.
                        </p>

                        @error($key)
                            <p class="{{ $error }}">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>
    </x-card>
</div>
