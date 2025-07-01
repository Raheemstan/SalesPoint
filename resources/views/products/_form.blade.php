@php
    $p = isset($edit) && $edit ? 'editProduct.' : '';
@endphp

@php
    $inputClass = "w-full rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 bg-white dark:bg-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none";
    $labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1";
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    <div>
        <label class="{{ $labelClass }}">Name</label>
        <input type="text" name="name" 
               x-model="{{ $p }}name"
               value="{{ old('name') }}"
               required 
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">SKU</label>
        <input type="text" name="sku" 
               x-model="{{ $p }}sku"
               value="{{ old('sku') }}"
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Barcode</label>
        <input type="text" name="barcode" 
               x-model="{{ $p }}barcode"
               value="{{ old('barcode') }}"
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Category</label>
        <select name="category_id" 
                x-model="{{ $p }}category_id"
                class="{{ $inputClass }}">
            <option value="">Select Category</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}">
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="{{ $labelClass }}">Cost Price (₦)</label>
        <input type="number" step="0.01" name="cost_price"
               x-model="{{ $p }}cost_price"
               value="{{ old('cost_price') }}"
               required 
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Sale Price (₦)</label>
        <input type="number" step="0.01" name="sale_price"
               x-model="{{ $p }}sale_price"
               value="{{ old('sale_price') }}"
               required 
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Stock Quantity</label>
        <input type="number" name="stock_quantity"
               x-model="{{ $p }}stock_quantity"
               value="{{ old('stock_quantity') }}"
               required 
               class="{{ $inputClass }}">
    </div>

</div>
