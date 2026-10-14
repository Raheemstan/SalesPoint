@extends('layouts.app')

@section('title', 'Import ' . term('product', true))

@php
    /*
    |--------------------------------------------------------------------------
    | Bulk import from a spreadsheet
    |--------------------------------------------------------------------------
    |
    | Two steps on one screen: upload, then confirm. The upload is a dry run -
    | the file is parked and the plan ($preview) is rendered, so nothing is
    | written until the person has seen what the file would do, row by row, with
    | the row number of every problem. The rejected-rows table is the point of
    | the whole screen, so it is listed first and the raw validation message is
    | printed as the validator wrote it.
    |
    | Load-bearing and left exactly as they were: the file field's name, the
    | token field, the skip_invalid checkbox and the template download link.
    */
    $productTerm = term('product');
    $many = Str::lower(term('product', true));
    $one = Str::lower($productTerm);

    $required = \App\Http\Controllers\ProductImportController::REQUIRED_HEADERS;
    $maxRows = \App\Http\Controllers\ProductImportController::MAX_ROWS;

    $btnPrimary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $btnSecondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';

    $willWrite = $preview && (count($preview['create']) || count($preview['update']));
@endphp

@section('content')
    <x-page-header :title="'Import ' . $many"
                   subtitle="Upload a CSV, check what it would do, then import it"
                   :back="route('products.index')"
                   :back-label="term('product', true)">
        <x-slot:actions>
            <a href="{{ route('products.import.template') }}" class="{{ $btnSecondary }}">
                <x-icon name="download" size="4" />
                Download template
            </a>
            <a href="{{ route('products.export') }}" class="{{ $btnSecondary }}">
                <x-icon name="download" size="4" />
                Export all {{ $many }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Step one: the file --}}
    <x-card title="Upload a spreadsheet"
            subtitle="Nothing is written until you have seen what the file would do">
        <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-300">
            Save your spreadsheet as CSV and upload it here. The next screen shows every row that would be
            created, updated or rejected, with the row number of each problem.
        </p>

        <form method="POST" action="{{ route('products.import.store') }}" enctype="multipart/form-data"
              class="mt-4 flex flex-wrap items-end gap-3">
            @csrf

            <div class="w-full sm:w-auto sm:min-w-72">
                <label for="file" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    CSV file
                </label>

                <input type="file" name="file" id="file" accept=".csv,text/csv" required
                       class="block w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm
                              shadow-sm file:mr-3 file:rounded file:border-0 file:bg-primary-50 file:px-3 file:py-1.5
                              file:text-sm file:font-medium file:text-primary-700
                              dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100
                              dark:file:bg-primary-900/40 dark:file:text-primary-200">

                @error('file')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="{{ $btnPrimary }}">
                <x-icon name="upload" size="4" />
                Check the file
            </button>
        </form>

        <x-slot:footer>
            <p>
                <span class="font-medium text-gray-700 dark:text-gray-200">Columns:</span>
                {{ implode(', ', $headers) }}.
                Only <span class="font-medium text-gray-700 dark:text-gray-200">{{ implode(', ', $required) }}</span>
                are required; anything else we do not recognise is ignored. Up to
                {{ number_format($maxRows) }} rows per file.
            </p>
            <p class="mt-2">
                A row is matched to an existing {{ $one }} by
                <span class="font-medium text-gray-700 dark:text-gray-200">sku</span>, then by
                <span class="font-medium text-gray-700 dark:text-gray-200">barcode</span>; anything else is created.
                {{ term('category', true) }} and {{ Str::lower(term('supplier', true)) }} are created automatically
                if the name is new.
                <span class="font-medium text-gray-700 dark:text-gray-200">stock_quantity</span> is only used for a
                new {{ $one }} (its opening stock) &mdash; changing the stock of an existing one needs a stock-take
                or an adjustment, so that it leaves a reason and a trail.
            </p>
        </x-slot:footer>
    </x-card>

    {{-- Step two: the dry run. Nothing has been written at this point. --}}
    @if ($preview)
        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat label="To create" :value="number_format(count($preview['create']))" icon="plus"
                    :hint="'new ' . $many" />

            <x-stat label="To update" :value="number_format(count($preview['update']))" icon="refresh"
                    hint="matched on sku or barcode" />

            {{-- invert: on a count of rejected rows, a rise is the bad direction. --}}
            <x-stat label="Rejected" :value="number_format(count($preview['errors']))" icon="alert-triangle" invert
                    :hint="count($preview['errors']) ? 'listed below' : 'nothing to fix'" />
        </div>

        <x-card title="Preview"
                :subtitle="number_format($preview['rows']) . ' row(s) read. Nothing has been imported yet.'"
                class="mt-4">
            <form method="POST" action="{{ route('products.import.store') }}"
                  class="flex flex-wrap items-center gap-4">
                @csrf
                <input type="hidden" name="token" value="{{ $preview['token'] }}">

                @if (count($preview['errors']))
                    <label class="flex min-h-touch items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" name="skip_invalid" value="1"
                               class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-700">
                        Skip the {{ count($preview['errors']) }} rejected row(s)
                    </label>
                @endif

                <button type="submit"
                        @class([
                            'inline-flex min-h-touch items-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold text-white',
                            'bg-primary-600 hover:bg-primary-700' => $willWrite,
                            'cursor-not-allowed bg-gray-400 dark:bg-gray-600' => ! $willWrite,
                        ])
                        @disabled(! $willWrite)>
                    <x-icon name="check-circle" size="4" />
                    Import {{ count($preview['create']) + count($preview['update']) }} row(s)
                </button>
            </form>

            @if (count($preview['errors']) && $willWrite)
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    Rejected rows are never imported. Tick the box above to import the rest of the file and deal
                    with them separately, or fix them in the spreadsheet and upload it again.
                </p>
            @endif
        </x-card>

        {{-- The rejected rows first: they are the reason to read this screen. --}}
        @if (count($preview['errors']))
            <x-card title="Rows that cannot be imported" :padding="false" class="mt-4">
                <x-slot:actions>
                    <x-badge variant="red">{{ count($preview['errors']) }} row(s)</x-badge>
                </x-slot:actions>

                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="w-20">Row</th>
                            <th>Name in the file</th>
                            <th>What is wrong</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($preview['errors'] as $error)
                        <tr>
                            <td class="font-mono tabular-nums">{{ $error['line'] }}</td>
                            <td>{{ $error['name'] ?: '(blank)' }}</td>
                            <td class="text-red-700 dark:text-red-400">
                                {{ implode(' ', $error['messages']) }}
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        @endif

        @foreach ([['create', 'Will be created', 'green'], ['update', 'Will be updated', 'blue']] as [$key, $heading, $colour])
            @if (count($preview[$key]))
                <x-card :title="$heading" :padding="false" class="mt-4">
                    <x-slot:actions>
                        <x-badge :variant="$colour">{{ count($preview[$key]) }} row(s)</x-badge>
                    </x-slot:actions>

                    <x-table>
                        <x-slot:head>
                            <tr>
                                <th class="w-20">Row</th>
                                <th>{{ $productTerm }}</th>
                                <th>SKU</th>
                                <th>{{ term('category') }}</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">
                                    {{ $key === 'create' ? 'Opening stock' : 'Stock (unchanged)' }}
                                </th>
                            </tr>
                        </x-slot:head>

                        @foreach ($preview[$key] as $entry)
                            <tr>
                                <td class="font-mono tabular-nums">{{ $entry['line'] }}</td>
                                <td class="font-medium">{{ $entry['name'] }}</td>
                                <td class="font-mono text-xs">
                                    {{ $entry['row']['sku'] ?? '' ?: ($entry['product']->sku ?? 'generated') }}
                                </td>
                                <td class="text-gray-600 dark:text-gray-300">{{ $entry['row']['category'] ?? '' }}</td>
                                <td class="text-right tabular-nums">{{ money($entry['row']['cost_price'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ money($entry['row']['sale_price'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">
                                    {{ $key === 'create'
                                        ? ($entry['row']['stock_quantity'] ?? 0)
                                        : $entry['product']->stock_quantity }}
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                </x-card>
            @endif
        @endforeach
    @endif
@endsection
