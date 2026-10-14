{{--
    The supplier form, shared by the "add" modal and each row's "edit" modal.
    Pass $supplier to edit one; pass null for a blank form.

    Everything is server-rendered rather than bound through Alpine so that a
    validation failure comes back with the user's own typing still in the fields.
    The hidden _supplier_id says which form was submitted, so a failed edit of one
    row does not repopulate every other row's modal with that row's input.

    Field ids are namespaced by that same key, because the list page renders this
    partial once per row and an id has to be unique on the page.
--}}
@php
    $supplier = $supplier ?? null;

    $mine = $supplier
        ? (int) old('_supplier_id') === (int) $supplier->id
        : old('_supplier_id') === 'new';

    // old() only when this is the form that was submitted.
    $was = fn($key, $fallback = null) => $mine ? old($key, $fallback) : $fallback;

    // Unique per rendered copy of this form.
    $uid = 'supplier-' . ($supplier->id ?? 'new');

    $inputClass = 'block w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                   focus:border-primary-500 focus:ring-primary-500
                   dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
@endphp

<input type="hidden" name="_supplier_id" value="{{ $supplier->id ?? 'new' }}">

<div class="grid grid-cols-1 gap-4 md:grid-cols-2">

    <div>
        <label for="{{ $uid }}-name" class="{{ $labelClass }}">
            Name <span class="text-red-600" aria-hidden="true">*</span>
        </label>
        <input type="text" id="{{ $uid }}-name" name="name" required maxlength="255"
               value="{{ $was('name', $supplier->name ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label for="{{ $uid }}-phone" class="{{ $labelClass }}">
            Phone <span class="text-red-600" aria-hidden="true">*</span>
        </label>
        <input type="tel" id="{{ $uid }}-phone" name="phone" required maxlength="20"
               value="{{ $was('phone', $supplier->phone ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label for="{{ $uid }}-email" class="{{ $labelClass }}">
            Email <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
        </label>
        <input type="email" id="{{ $uid }}-email" name="email" maxlength="255"
               value="{{ $was('email', $supplier->email ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label for="{{ $uid }}-address" class="{{ $labelClass }}">
            Address <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
        </label>
        <input type="text" id="{{ $uid }}-address" name="address" maxlength="255"
               value="{{ $was('address', $supplier->address ?? '') }}" class="{{ $inputClass }}">
    </div>

    <div>
        <label for="{{ $uid }}-opening-balance" class="{{ $labelClass }}">Opening balance ({{ currency() }})</label>
        <input type="number" id="{{ $uid }}-opening-balance" step="0.01" min="0" name="opening_balance"
               value="{{ $was('opening_balance', $supplier->opening_balance ?? '0.00') }}"
               class="{{ $inputClass }} tabular-nums">
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            What was already owed to them before SalesPoint started keeping the account.
        </p>
    </div>

    <div class="flex items-start md:items-end">
        {{-- An unticked box is simply absent from the request, so post a 0 ahead of it. --}}
        <input type="hidden" name="is_active" value="0">
        <label class="flex min-h-touch items-center gap-2.5 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" id="{{ $uid }}-is-active" name="is_active" value="1"
                   class="h-4 w-4 shrink-0 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                   @checked($was('is_active', $supplier->is_active ?? true))>
            Active (offered when recording a {{ Str::lower(term('purchase', false, 'Purchase')) }})
        </label>
    </div>

    <div class="md:col-span-2">
        <label for="{{ $uid }}-notes" class="{{ $labelClass }}">
            Notes <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
        </label>
        <textarea id="{{ $uid }}-notes" name="notes" rows="2" maxlength="2000"
                  class="{{ $inputClass }}">{{ $was('notes', $supplier->notes ?? '') }}</textarea>
    </div>

</div>
