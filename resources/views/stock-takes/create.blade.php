@extends('layouts.app')

@section('title', 'New Count Sheet')

@section('content')
    <div class="max-w-2xl space-y-4">
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                A count sheet freezes what the system believes right now, so you can compare it with what is
                actually on the shelf. Counting everything at once is slow - most shops count one
                {{ strtolower(term('category', false, 'category')) }} or one supplier at a time.
                {{ term('product', true) }} that do not track stock (services, for example) are never counted.
            </p>

            <form method="POST" action="{{ route('stock-takes.store') }}">
                @csrf

                <x-select name="category_id" label="Limit to one category (optional)" :options="$categories"
                          option-label="name" option-value="id" placeholder="-- All categories --"
                          :selected="request('category_id')" />

                <x-select name="supplier_id" label="Limit to one supplier (optional)" :options="$suppliers"
                          option-label="name" option-value="id" placeholder="-- All suppliers --"
                          :selected="request('supplier_id')" />

                <x-textarea name="note" label="Note (optional)" rows="2"
                            placeholder="e.g. month-end count, back store only" />

                <div class="flex items-center justify-between mt-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ number_format($countable) }} countable
                        {{ strtolower(term('product', $countable !== 1)) }} match the current selection.
                    </span>
                    <div class="flex gap-2">
                        <a href="{{ route('stock-takes.index') }}"
                           class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">Cancel</a>
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                            Create sheet
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
