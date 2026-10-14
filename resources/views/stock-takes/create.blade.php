@extends('layouts.app')

@section('title', 'New Count Sheet')

@section('content')
    <x-page-header title="New count sheet"
                   subtitle="Freeze what the system believes, then go and count it"
                   :back="route('stock-takes.index')" back-label="Stock takes" />

    {{--
        The form wraps the card so the buttons can live in the card's footer and
        still post with the fields above them.
    --}}
    <form method="POST" action="{{ route('stock-takes.store') }}" class="max-w-2xl">
        @csrf

        <x-card>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                A count sheet freezes what the system believes right now, so you can compare it with what is
                actually on the shelf. Counting everything at once is slow - most shops count one
                {{ strtolower(term('category', false, 'category')) }} or one supplier at a time.
                {{ term('product', true) }} that do not track stock (services, for example) are never counted.
            </p>

            {{-- Searchable: a real shop has more categories and suppliers than fit in a glance. --}}
            <x-select name="category_id" label="Limit to one category (optional)" :options="$categories"
                      option-label="name" option-value="id" placeholder="-- All categories --"
                      :selected="request('category_id')" searchable />

            <x-select name="supplier_id" label="Limit to one supplier (optional)" :options="$suppliers"
                      option-label="name" option-value="id" placeholder="-- All suppliers --"
                      :selected="request('supplier_id')" searchable />

            <x-textarea name="note" label="Note (optional)" rows="2"
                        placeholder="e.g. month-end count, back store only" />

            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ number_format($countable) }} countable
                        {{ strtolower(term('product', $countable !== 1)) }} match the current selection.
                    </span>

                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('stock-takes.index') }}"
                           class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                            Cancel
                        </a>

                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            <x-icon name="clipboard-check" size="4" />
                            Create sheet
                        </button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
@endsection
