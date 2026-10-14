{{--
    Add / edit one customer. Lives in a modal on the list page: Alpine keeps the
    row being edited in `form`, so one form does both jobs - a blank `form.id`
    posts to customers.store, anything else PUTs to customers.update.

    The shell prints validation errors above the page, so this form does not
    repeat them.
--}}
@php
    $customerTerm = Str::lower(term('customer'));

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $optional = 'font-normal text-gray-400 dark:text-gray-500';
    $field = 'block w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
              focus:border-primary-500 focus:ring-primary-500
              dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
@endphp

{{-- max-h/overflow: the modal has to be usable at phone height. --}}
<form method="POST" class="max-h-[90vh] overflow-y-auto"
      :action="form.id ? '{{ route('customers.index') }}/' + form.id : '{{ route('customers.store') }}'">
    @csrf
    {{-- POST for a new one, PUT for an edit: the same form, spoofed. --}}
    <input type="hidden" name="_method" :value="form.id ? 'PUT' : 'POST'">
    {{-- Not validated, only carried: it is what reopens the modal on the right row after a validation failure. --}}
    <input type="hidden" name="id" :value="form.id">

    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100"
            x-text="form.id ? 'Edit {{ $customerTerm }}' : 'New {{ $customerTerm }}'"></h2>

        <button type="button" x-on:click="$dispatch('close')" aria-label="Close"
                class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
            <x-icon name="x" size="5" />
        </button>
    </div>

    <div class="space-y-4 p-4 sm:p-5">
        <div>
            <label for="customer-name" class="{{ $label }}">
                Name <span class="text-red-600" aria-hidden="true">*</span>
            </label>
            <input type="text" id="customer-name" name="name" x-model="form.name" required maxlength="255"
                   class="{{ $field }}">
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="customer-phone" class="{{ $label }}">
                    Phone <span class="{{ $optional }}">(optional)</span>
                </label>
                <input type="tel" id="customer-phone" name="phone" x-model="form.phone" maxlength="30"
                       class="{{ $field }}">
            </div>
            <div>
                <label for="customer-email" class="{{ $label }}">
                    Email <span class="{{ $optional }}">(optional)</span>
                </label>
                <input type="email" id="customer-email" name="email" x-model="form.email" maxlength="255"
                       class="{{ $field }}">
            </div>
        </div>

        <div>
            <label for="customer-address" class="{{ $label }}">
                Address <span class="{{ $optional }}">(optional)</span>
            </label>
            <input type="text" id="customer-address" name="address" x-model="form.address" maxlength="255"
                   class="{{ $field }}">
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="customer-credit-limit" class="{{ $label }}">Credit limit ({{ currency() }})</label>
                <input type="number" id="customer-credit-limit" name="credit_limit" x-model="form.credit_limit"
                       step="0.01" min="0" class="{{ $field }} tabular-nums">
                <p class="{{ $hint }}">0 = no limit. Otherwise the till refuses a sale that would go past it.</p>
            </div>

            <div class="flex items-start sm:items-end">
                <label class="flex min-h-touch items-center gap-2.5 text-sm text-gray-700 dark:text-gray-200">
                    {{-- The hidden 0 goes first so an unticked box still posts a value
                         and really does deactivate; a ticked box overrides it. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                           class="h-4 w-4 shrink-0 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                    Active (shown in the till's picker and may buy on credit)
                </label>
            </div>
        </div>

        <div>
            <label for="customer-notes" class="{{ $label }}">
                Notes <span class="{{ $optional }}">(optional)</span>
            </label>
            <textarea id="customer-notes" name="notes" x-model="form.notes" rows="2" maxlength="2000"
                      class="{{ $field }}"></textarea>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
        <button type="button" x-on:click="$dispatch('close')"
                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
            Cancel
        </button>
        <button type="submit"
                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
            <x-icon name="check-circle" size="4" />
            Save
        </button>
    </div>
</form>
