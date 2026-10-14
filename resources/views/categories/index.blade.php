@extends('layouts.app')

@section('title', term('category', true))

@php
    /*
    |--------------------------------------------------------------------------
    | How the catalogue is grouped
    |--------------------------------------------------------------------------
    |
    | Every noun on this screen comes from term(): a pharmacy groups drugs into
    | "Drug classes", a restaurant groups its menu into "Menu sections". The
    | markup used to say "Category" in nine places, which is wrong on most of
    | the trades this app ships presets for - and wrong in the one place the
    | sidebar already got right.
    |
    | Add and edit stay as two hand-rolled Alpine modals rather than x-modal:
    | that component is opened by a dispatched event, whereas the edit form here
    | is populated from the row it was opened from (editCategory), which is what
    | lets the whole screen be one page with no edit route behind it.
    |
    | Nothing here prints flash messages or validation errors: the shell already
    | renders both, above the content.
    */
    $categoryTerm = term('category');
    $categoriesTerm = term('category', true);
    $one = Str::lower($categoryTerm);
    $many = Str::lower($categoriesTerm);

    // Through @js so a trade whose noun carries a quote cannot break the handler.
    $deleteWarning = 'Delete this ' . $one . '? This cannot be undone.';

    $btnPrimary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $btnSecondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';

    $backdrop = 'fixed inset-0 z-modal flex items-center justify-center bg-gray-900/50 p-4';
    $panel = 'max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
@endphp

@section('content')
<div x-data="{ addModal: false, editModal: false, editCategory: {} }"
     @keydown.escape.window="addModal = false; editModal = false">

    <x-page-header :title="$categoriesTerm"
                   :subtitle="$categories->total() === 1
                        ? 'One ' . $one . ' in the catalogue'
                        : number_format($categories->total()) . ' ' . $many . ' in the catalogue'">
        <x-slot:actions>
            <button type="button" @click="addModal = true" class="{{ $btnPrimary }}">
                <x-icon name="plus" size="4" />
                Add {{ $one }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="w-12">#</th>
                    <th>Name</th>
                    <th class="text-right">Actions</th>
                </tr>
            </x-slot:head>

            @forelse ($categories as $category)
                <tr>
                    {{-- Numbered across the whole list, not 1-10 again on every page. --}}
                    <td class="tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $categories->firstItem() + $loop->index }}
                    </td>
                    <td class="font-medium">{{ $category->name }}</td>
                    <td>
                        <div class="flex items-center justify-end gap-3">
                            <button type="button"
                                    @click="editModal = true; editCategory = {{ $category->toJson() }}"
                                    class="inline-flex min-h-touch items-center font-medium text-primary-600 hover:underline dark:text-primary-400">
                                Edit
                            </button>

                            {{-- A native confirm() on a destructive submit, as everywhere else. --}}
                            <form action="{{ route('categories.destroy', $category->id) }}" method="POST"
                                  onsubmit="return confirm(@js($deleteWarning));">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex min-h-touch items-center font-medium text-red-600 hover:underline dark:text-red-400">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">
                        <x-empty icon="folder" :title="'No ' . $many . ' yet'"
                                 :message="Str::ucfirst($many) . ' are how the catalogue is grouped, on the till and on every report.'">
                            <x-slot:action>
                                <button type="button" @click="addModal = true" class="{{ $btnPrimary }}">
                                    <x-icon name="plus" size="4" />
                                    Add the first {{ $one }}
                                </button>
                            </x-slot:action>
                        </x-empty>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($categories->hasPages())
            <x-slot:footer>{{ $categories->links() }}</x-slot:footer>
        @endif
    </x-card>

    {{-- Add --}}
    <div x-show="addModal" x-cloak @click.self="addModal = false"
         x-transition.opacity.duration.150ms
         role="dialog" aria-modal="true" aria-labelledby="add-category-title"
         class="{{ $backdrop }}">
        <div class="{{ $panel }}">
            <h2 id="add-category-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                Add {{ $one }}
            </h2>

            <form method="POST" action="{{ route('categories.store') }}" class="mt-4">
                @csrf

                <div>
                    <label for="add-category-name"
                           class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Name
                    </label>
                    <input type="text" name="name" id="add-category-name" required
                           autocomplete="off" class="{{ $control }}">
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" @click="addModal = false" class="{{ $btnSecondary }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit --}}
    <div x-show="editModal" x-cloak @click.self="editModal = false"
         x-transition.opacity.duration.150ms
         role="dialog" aria-modal="true" aria-labelledby="edit-category-title"
         class="{{ $backdrop }}">
        <div class="{{ $panel }}">
            <h2 id="edit-category-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                Rename {{ $one }}
            </h2>

            <form method="POST" :action="'/categories/' + editCategory.id" class="mt-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-category-name"
                           class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Name
                    </label>
                    <input type="text" name="name" id="edit-category-name" x-model="editCategory.name" required
                           autocomplete="off" class="{{ $control }}">
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" @click="editModal = false" class="{{ $btnSecondary }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
