@extends('layouts.app')

@section('title', 'Import ' . term('product', true))

@section('content')
    <div class="space-y-4">

        {{-- Upload --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <div class="flex flex-wrap justify-between items-start gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-lg font-semibold mb-1">Import {{ strtolower(term('product', true)) }} from a spreadsheet</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Save your spreadsheet as CSV and upload it here. Nothing is written until you have seen
                        what the file would do: the next screen shows every row that would be created, updated or
                        rejected, with the row number of each problem.
                    </p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('products.import.template') }}"
                       class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                        Download template
                    </a>
                    <a href="{{ route('products.export') }}"
                       class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                        Export all {{ strtolower(term('product', true)) }}
                    </a>
                </div>
            </div>

            <form method="POST" action="{{ route('products.import.store') }}" enctype="multipart/form-data"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="file" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">CSV file</label>
                    <input type="file" name="file" id="file" accept=".csv,text/csv" required
                           class="px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                    @error('file')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                    Check the file
                </button>
            </form>

            <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                <p class="mb-1">
                    <span class="font-medium">Columns:</span> {{ implode(', ', $headers) }}.
                    Only <span class="font-medium">{{ implode(', ', \App\Http\Controllers\ProductImportController::REQUIRED_HEADERS) }}</span>
                    are required; anything else we do not recognise is ignored. Up to
                    {{ number_format(\App\Http\Controllers\ProductImportController::MAX_ROWS) }} rows per file.
                </p>
                <p>
                    A row is matched to an existing {{ strtolower(term('product')) }} by <span class="font-medium">sku</span>,
                    then by <span class="font-medium">barcode</span>; anything else is created.
                    Categories and suppliers are created automatically if the name is new.
                    <span class="font-medium">stock_quantity</span> is only used for a new
                    {{ strtolower(term('product')) }} (its opening stock) - changing the stock of an existing one
                    needs a stock-take or an adjustment, so that it leaves a reason and a trail.
                </p>
            </div>
        </div>

        {{-- Preview: this is the dry run, nothing has been written yet --}}
        @if ($preview)
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                    <div>
                        <h3 class="text-lg font-semibold">Preview</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ number_format($preview['rows']) }} row(s) read. Nothing has been imported yet.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('products.import.store') }}" class="flex items-center gap-3">
                        @csrf
                        <input type="hidden" name="token" value="{{ $preview['token'] }}">

                        @if (count($preview['errors']))
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" name="skip_invalid" value="1"
                                       class="rounded border-gray-300 dark:border-gray-600">
                                Skip the {{ count($preview['errors']) }} rejected row(s)
                            </label>
                        @endif

                        <button type="submit"
                                @class([
                                    'px-4 py-2 rounded text-white text-sm',
                                    'bg-green-600 hover:bg-green-700' => count($preview['create']) || count($preview['update']),
                                    'bg-gray-400 cursor-not-allowed' => !count($preview['create']) && !count($preview['update']),
                                ])
                                @disabled(!count($preview['create']) && !count($preview['update']))>
                            Import {{ count($preview['create']) + count($preview['update']) }} row(s)
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div class="rounded border border-green-200 dark:border-green-900 p-3">
                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400">To create</div>
                        <div class="text-xl font-semibold text-green-700 dark:text-green-400">{{ count($preview['create']) }}</div>
                    </div>
                    <div class="rounded border border-blue-200 dark:border-blue-900 p-3">
                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400">To update</div>
                        <div class="text-xl font-semibold text-blue-700 dark:text-blue-400">{{ count($preview['update']) }}</div>
                    </div>
                    <div class="rounded border border-red-200 dark:border-red-900 p-3">
                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Rejected</div>
                        <div class="text-xl font-semibold text-red-700 dark:text-red-400">{{ count($preview['errors']) }}</div>
                    </div>
                </div>

                @if (count($preview['errors']))
                    <h4 class="font-semibold mb-2 text-red-700 dark:text-red-400">Rows that cannot be imported</h4>
                    <div class="overflow-x-auto mb-6">
                        <table class="w-full table-auto text-sm">
                            <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                <tr>
                                    <th class="p-2 text-left w-20">Row</th>
                                    <th class="p-2 text-left">Name in the file</th>
                                    <th class="p-2 text-left">What is wrong</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['errors'] as $error)
                                    <tr class="border-t dark:border-gray-700">
                                        <td class="p-2 font-mono">{{ $error['line'] }}</td>
                                        <td class="p-2">{{ $error['name'] ?: '(blank)' }}</td>
                                        <td class="p-2 text-red-600 dark:text-red-400">
                                            {{ implode(' ', $error['messages']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @foreach ([['create', 'Will be created', 'green'], ['update', 'Will be updated', 'blue']] as [$key, $heading, $colour])
                    @if (count($preview[$key]))
                        <h4 class="font-semibold mb-2">{{ $heading }} ({{ count($preview[$key]) }})</h4>
                        <div class="overflow-x-auto mb-6">
                            <table class="w-full table-auto text-sm">
                                <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                    <tr>
                                        <th class="p-2 text-left w-20">Row</th>
                                        <th class="p-2 text-left">{{ term('product') }}</th>
                                        <th class="p-2 text-left">SKU</th>
                                        <th class="p-2 text-left">Category</th>
                                        <th class="p-2 text-right">Cost</th>
                                        <th class="p-2 text-right">Price</th>
                                        <th class="p-2 text-right">{{ $key === 'create' ? 'Opening stock' : 'Stock (unchanged)' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview[$key] as $entry)
                                        <tr class="border-t dark:border-gray-700">
                                            <td class="p-2 font-mono">{{ $entry['line'] }}</td>
                                            <td class="p-2">{{ $entry['name'] }}</td>
                                            <td class="p-2 font-mono text-xs">
                                                {{ $entry['row']['sku'] ?? '' ?: ($entry['product']->sku ?? 'generated') }}
                                            </td>
                                            <td class="p-2">{{ $entry['row']['category'] ?? '' }}</td>
                                            <td class="p-2 text-right">{{ money($entry['row']['cost_price'] ?? 0) }}</td>
                                            <td class="p-2 text-right">{{ money($entry['row']['sale_price'] ?? 0) }}</td>
                                            <td class="p-2 text-right">
                                                {{ $key === 'create'
                                                    ? ($entry['row']['stock_quantity'] ?? 0)
                                                    : $entry['product']->stock_quantity }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
@endsection
