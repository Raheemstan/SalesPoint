@php
    /*
    |--------------------------------------------------------------------------
    | reports.partials.filter-bar  -  the filter row every report shares
    |--------------------------------------------------------------------------
    |
    |   @include('reports.partials.filter-bar', [
    |       'startDate' => $startDate,
    |       'endDate' => $endDate,
    |       'fields' => [
    |           ['name' => 'type', 'label' => 'Direction', 'options' => $types,
    |            'value' => $type, 'placeholder' => 'Any'],
    |           ['name' => 'search', 'label' => 'Search', 'value' => $search],
    |       ],
    |   ])
    |
    | Declared as data rather than passed as markup, because an @include has no
    | slot and nine reports would otherwise each repeat the same form.
    |
    | One row, above everything it scopes: never a filter per chart, or the
    | charts on a page stop agreeing with each other.
    |
    | Keys, all optional:
    |   startDate / endDate  render the date pair (omit both for a report with
    |                        no range, like stock valuation)
    |   startKey / endKey    when the fields are not called start_date/end_date
    |                        (profit & loss uses from/to)
    |   fields               extra selects and inputs, in order
    |   csv                  false to drop the CSV button
    |   csvRoute             where the CSV button points (default: this page
    |                        with ?export=csv)
    |   note                 a line of explanation under the row
    */
    $startKey = $startKey ?? 'start_date';
    $endKey = $endKey ?? 'end_date';
    $fields = $fields ?? [];
    $csv = $csv ?? true;
    $hasRange = isset($startDate) && isset($endDate);

    $label = 'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';
    $control = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                focus:border-primary-500 focus:ring-primary-500
                dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    // Everything currently applied, so the CSV button exports what is on screen.
    $current = array_filter(request()->query(), fn($value, $key) => $key !== 'export' && $value !== '', ARRAY_FILTER_USE_BOTH);
@endphp

<form method="GET" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
    <div class="flex flex-wrap items-end gap-3">
        @if ($hasRange)
            <div class="w-full sm:w-auto">
                <label for="{{ $startKey }}" class="{{ $label }}">From</label>
                <input type="date" id="{{ $startKey }}" name="{{ $startKey }}" value="{{ $startDate }}"
                       class="{{ $control }}">
            </div>
            <div class="w-full sm:w-auto">
                <label for="{{ $endKey }}" class="{{ $label }}">To</label>
                <input type="date" id="{{ $endKey }}" name="{{ $endKey }}" value="{{ $endDate }}"
                       class="{{ $control }}">
            </div>
        @endif

        @foreach ($fields as $field)
            @php
                $name = $field['name'];
                $value = $field['value'] ?? null;
            @endphp

            <div class="w-full sm:w-auto {{ $field['width'] ?? 'sm:min-w-44' }}">
                <label for="filter-{{ $name }}" class="{{ $label }}">{{ $field['label'] }}</label>

                @if (isset($field['options']))
                    <select id="filter-{{ $name }}" name="{{ $name }}" class="{{ $control }}">
                        @if (array_key_exists('placeholder', $field))
                            <option value="">{{ $field['placeholder'] }}</option>
                        @endif
                        @foreach ($field['options'] as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}"
                                @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                @else
                    <input type="{{ $field['type'] ?? 'text' }}"
                           id="filter-{{ $name }}" name="{{ $name }}" value="{{ $value }}"
                           @isset($field['min']) min="{{ $field['min'] }}" @endisset
                           @isset($field['max']) max="{{ $field['max'] }}" @endisset
                           placeholder="{{ $field['placeholder'] ?? '' }}"
                           class="{{ $control }}">
                @endif
            </div>
        @endforeach

        <div class="flex min-h-touch flex-wrap items-center gap-2">
            <button type="submit"
                    class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white
                           hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2
                           dark:focus:ring-offset-gray-800">
                <x-icon name="search" size="4" />
                Apply
            </button>

            <a href="{{ url()->current() }}"
               class="inline-flex min-h-touch items-center rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100
                      dark:text-gray-300 dark:hover:bg-gray-700">
                Reset
            </a>

            @if ($csv)
                <a href="{{ $csvRoute ?? url()->current() . '?' . http_build_query($current + ['export' => 'csv']) }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium
                          text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    <x-icon name="download" size="4" />
                    CSV
                </a>
            @endif
        </div>
    </div>

    @isset($note)
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ $note }}</p>
    @endisset
</form>
