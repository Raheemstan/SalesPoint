{{--
    The supplier form, shared by the "add" modal and each row's "edit" modal.
    Pass $supplier to edit one; leave it out for a blank form.

    Everything is server-rendered rather than bound through Alpine so that a
    validation failure comes back with the user's own typing still in the fields.
    The hidden _supplier_id says which form was submitted, so a failed edit of one
    row does not repopulate every other row's modal with that row's input.
--}}
@php
    $supplier = $supplier ?? null;

    $mine = $supplier
        ? (int) old('_supplier_id') === (int) $supplier->id
        : old('_supplier_id') === 'new';

    // old() only when this is the form that was submitted.
    $was = fn($key, $fallback = null) => $mine ? old($key, $fallback) : $fallback;

    $inputClass = 'w-full rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:outline-none';
    $labelClass = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
@endphp

<input type="hidden" name="_supplier_id" value="{{ $supplier->id ?? 'new' }}">

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <div>
        <label class="{{ $labelClass }}">Name</label>
        <input type="text" name="name" required maxlength="255"
               value="{{ $was('name', $supplier->name ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Phone</label>
        <input type="text" name="phone" required maxlength="20"
               value="{{ $was('phone', $supplier->phone ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Email</label>
        <input type="email" name="email" maxlength="255"
               value="{{ $was('email', $supplier->email ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Address</label>
        <input type="text" name="address" maxlength="255"
               value="{{ $was('address', $supplier->address ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Opening balance ({{ currency() }})</label>
        <input type="number" step="0.01" min="0" name="opening_balance"
               value="{{ $was('opening_balance', $supplier->opening_balance ?? '0.00') }}" class="{{ $inputClass }}">
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            What was already owed to them before SalesPoint started keeping the account.
        </p>
    </div>

    <div class="flex items-end">
        {{-- An unticked box is simply absent from the request, so post a 0 ahead of it. --}}
        <input type="hidden" name="is_active" value="0">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 mb-2">
            <input type="checkbox" name="is_active" value="1" class="rounded"
                   @checked($was('is_active', $supplier->is_active ?? true))>
            Active (offered when recording a {{ Str::lower(term('purchase', false, 'Purchase')) }})
        </label>
    </div>

    <div class="md:col-span-2">
        <label class="{{ $labelClass }}">Notes</label>
        <textarea name="notes" rows="2" maxlength="2000"
                  class="{{ $inputClass }}">{{ $was('notes', $supplier->notes ?? '') }}</textarea>
    </div>

</div>
