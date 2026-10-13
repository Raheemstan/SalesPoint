{{--
    Add / edit one customer. Lives in a modal on the list page: Alpine keeps the
    row being edited in `form`, so one form does both jobs - a blank `form.id`
    posts to customers.store, anything else PUTs to customers.update.
--}}
@php
    $label = 'block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1';
    $field = 'w-full rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100';
@endphp

<form method="POST" class="p-6 space-y-4"
      :action="form.id ? '{{ route('customers.index') }}/' + form.id : '{{ route('customers.store') }}'">
    @csrf
    {{-- POST for a new one, PUT for an edit: the same form, spoofed. --}}
    <input type="hidden" name="_method" :value="form.id ? 'PUT' : 'POST'">
    {{-- Not validated, only carried: it is what reopens the modal on the right row after a validation failure. --}}
    <input type="hidden" name="id" :value="form.id">

    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100"
        x-text="form.id ? 'Edit {{ strtolower(term('customer')) }}' : 'New {{ strtolower(term('customer')) }}'"></h3>

    <div>
        <label class="{{ $label }}">Name</label>
        <input type="text" name="name" x-model="form.name" required maxlength="255" class="{{ $field }}">
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="{{ $label }}">Phone <span class="text-gray-400">(optional)</span></label>
            <input type="text" name="phone" x-model="form.phone" maxlength="30" class="{{ $field }}">
        </div>
        <div>
            <label class="{{ $label }}">Email <span class="text-gray-400">(optional)</span></label>
            <input type="email" name="email" x-model="form.email" maxlength="255" class="{{ $field }}">
        </div>
    </div>

    <div>
        <label class="{{ $label }}">Address</label>
        <input type="text" name="address" x-model="form.address" maxlength="255" class="{{ $field }}">
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="{{ $label }}">Credit limit ({{ currency() }})</label>
            <input type="number" name="credit_limit" x-model="form.credit_limit" step="0.01" min="0" class="{{ $field }}">
            <p class="text-xs text-gray-500 mt-1">0 = no limit. Otherwise the till refuses a sale that would go past it.</p>
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm">
                {{-- The hidden 0 goes first so an unticked box still posts a value
                     and really does deactivate; a ticked box overrides it. --}}
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                       class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                Active (shown in the till's picker and may buy on credit)
            </label>
        </div>
    </div>

    <div>
        <label class="{{ $label }}">Notes</label>
        <textarea name="notes" x-model="form.notes" rows="2" maxlength="2000" class="{{ $field }}"></textarea>
    </div>

    <div class="flex justify-end gap-2 pt-2">
        <button type="button" x-on:click="$dispatch('close')"
                class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</button>
        <button type="submit" class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white">Save</button>
    </div>
</form>
