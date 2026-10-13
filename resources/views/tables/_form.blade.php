@php
    /*
    |--------------------------------------------------------------------------
    | tables/_form - the manage-tables modal (manager / admin only)
    |--------------------------------------------------------------------------
    |
    | One modal does both adding and editing. Rendering a modal per table would
    | put thirty copies of this form in the page for a thirty-table restaurant;
    | instead the floor view dispatches a `manage-table` event carrying the row
    | to edit (or `mode: 'create'` for a blank one) and Alpine fills the fields.
    |
    | The delete button posts a separate form because it is a different verb, and
    | it is only offered when editing - there is nothing to delete when adding.
    | The controller refuses to delete a table that has an open order or any
    | history and says to deactivate instead, so the confirm here is a courtesy
    | rather than the real guard.
    */
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-200';
    $input = 'mt-1 block w-full min-h-touch rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $tableWord = lcfirst(term('table', false, 'Table'));
@endphp

<div x-data="{
        mode: 'create',
        url: '{{ route('tables.store') }}',
        destroy: null,
        form: { name: '', area: '', seats: 4, sort_order: 0, is_active: true },
        open(detail) {
            this.mode = detail.mode;
            this.destroy = detail.destroy ?? null;
            this.url = detail.url ?? '{{ route('tables.store') }}';
            this.form = {
                name: detail.name ?? '',
                area: detail.area ?? '',
                seats: detail.seats ?? 4,
                sort_order: detail.sort_order ?? 0,
                is_active: detail.is_active ?? true
            };
            $dispatch('open-modal', 'manage-table');
        }
     }"
     @manage-table.window="open($event.detail)">

    <x-modal name="manage-table" maxWidth="md" focusable>
        <form method="POST" :action="url" class="p-5">
            @csrf
            {{-- Spoofed only when editing; adding is a plain POST to tables.store. --}}
            <template x-if="mode === 'edit'">
                <input type="hidden" name="_method" value="PUT">
            </template>

            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                x-text="mode === 'edit' ? 'Edit {{ $tableWord }}' : 'Add {{ $tableWord }}'"></h2>

            <div class="mt-4 space-y-3">
                <label class="{{ $label }}">
                    Name
                    <input type="text" name="name" x-model="form.name" required maxlength="50"
                           placeholder="T1, Terrace 3, Booth 2" class="{{ $input }}">
                </label>

                <label class="{{ $label }}">
                    Area <span class="font-normal text-gray-500 dark:text-gray-400">(optional)</span>
                    <input type="text" name="area" x-model="form.area" maxlength="50"
                           placeholder="Terrace, Upstairs, Bar" class="{{ $input }}">
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="{{ $label }}">
                        Seats
                        <input type="number" name="seats" x-model="form.seats" required min="1" max="99" class="{{ $input }}">
                    </label>

                    <label class="{{ $label }}">
                        Sort order
                        <input type="number" name="sort_order" x-model="form.sort_order" min="0" max="9999" class="{{ $input }}">
                    </label>
                </div>

                {{-- A big, obvious switch: this is the control that retires a table. --}}
                <label class="flex min-h-touch cursor-pointer items-center gap-3 rounded-lg bg-gray-50 px-3 dark:bg-gray-700/50">
                    <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                           class="h-5 w-5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700">
                    <span class="text-sm text-gray-700 dark:text-gray-200">
                        In service
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Turn off to retire the {{ $tableWord }} without losing its history
                        </span>
                    </span>
                </label>
            </div>

            <div class="mt-5 flex gap-2">
                <button type="button" @click="$dispatch('close-modal', 'manage-table')"
                        class="min-h-touch flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700
                               hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    Cancel
                </button>
                <button class="min-h-touch flex-1 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                    Save
                </button>
            </div>
        </form>

        {{-- Outside the form above: a nested <form> is invalid HTML and silently breaks both. --}}
        <template x-if="mode === 'edit' && destroy">
            <form method="POST" :action="destroy"
                  class="border-t border-gray-200 px-5 py-3 dark:border-gray-700"
                  @submit="if (! confirm('Delete this ' + '{{ $tableWord }}' + ' for good?')) $event.preventDefault()">
                @csrf
                @method('DELETE')
                <button class="min-h-touch w-full rounded-lg text-sm font-semibold text-red-600 hover:bg-red-50
                               dark:text-red-400 dark:hover:bg-red-900/30">
                    Delete this {{ $tableWord }}
                </button>
            </form>
        </template>
    </x-modal>
</div>
