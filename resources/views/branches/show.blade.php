@extends('layouts.app')

@section('title', $branch->label())

@section('content')
    <div class="space-y-4">

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Sales</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($sales) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Takings</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ money($takings) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Coming in</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($incoming) }}</div>
                {{-- Said out loud, because in-transit stock is deliberately counted
                     on no shelf at all and the figures would not otherwise add up. --}}
                <div class="text-xs text-gray-500 dark:text-gray-400">units on their way here</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Gone out</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($outgoing) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">units sent, not yet received</div>
            </div>
        </div>

        @if ($canManage)
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Branch details</h2>

                <form method="POST" action="{{ route('branches.update', $branch) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    @csrf
                    @method('PUT')

                    <div class="lg:col-span-2">
                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Name</label>
                        <input type="text" name="name" id="name" value="{{ old('name', $branch->name) }}" required maxlength="255"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Code</label>
                        <input type="text" name="code" id="code" value="{{ old('code', $branch->code) }}" required maxlength="12"
                               class="w-full px-3 py-2 border rounded-md shadow-sm uppercase dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>

                    <div class="lg:col-span-2">
                        <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Address</label>
                        <input type="text" name="address" id="address" value="{{ old('address', $branch->address) }}" maxlength="255"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Phone</label>
                        <input type="text" name="phone" id="phone" value="{{ old('phone', $branch->phone) }}" maxlength="40"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                    </div>

                    <div class="sm:col-span-2 lg:col-span-6 flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($branch->is_active)
                                   class="rounded border-gray-300 dark:border-gray-600">
                            Open for trade
                        </label>

                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" name="is_default" value="1" @checked($branch->is_default)
                                   @disabled($branch->is_default)
                                   class="rounded border-gray-300 dark:border-gray-600">
                            Default branch
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                (where anything with no branch of its own is filed)
                            </span>
                        </label>

                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                            Save
                        </button>
                    </div>
                </form>

                @can('delete', $branch)
                    <form method="POST" action="{{ route('branches.destroy', $branch) }}"
                          class="mt-4 pt-4 border-t dark:border-gray-700"
                          onsubmit="return confirm('Close {{ $branch->name }}? Its history is kept and it can be reopened.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 dark:text-red-400 hover:underline">
                            Close this branch
                        </button>
                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                            Nothing is deleted &mdash; a branch is stamped on every sale, drawer and stock movement it ever made.
                        </span>
                    </form>
                @endcan
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">
                Who works here
            </h2>

            @if ($staff->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Nobody is assigned to this branch yet.</p>
            @else
                <ul class="flex flex-wrap gap-2">
                    @foreach ($staff as $member)
                        <li class="px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-sm text-gray-800 dark:text-gray-200">
                            {{ $member->name }}
                            <span class="text-xs text-gray-500 dark:text-gray-400 capitalize">{{ $member->role }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">
                On this branch's shelves
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">{{ term('product') }}</th>
                            <th class="p-2 text-left">SKU</th>
                            <th class="p-2 text-left">{{ term('category') }}</th>
                            <th class="p-2 text-right">Here</th>
                            <th class="p-2 text-right">Group total</th>
                            <th class="p-2 text-right">At cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stock as $product)
                            {{-- `branch_stock_quantity` is this branch's shelf, loaded by
                                 withBranchStock($branch) as one subquery for the whole page.
                                 Calling stockAt($branch) here instead would be a query per row. --}}
                            @php $here = (int) $product->branch_stock_quantity; @endphp
                            <tr class="border-t dark:border-gray-700">
                                <td class="p-2">{{ $product->displayName() }}</td>
                                <td class="p-2 font-mono text-xs">{{ $product->sku ?: '-' }}</td>
                                <td class="p-2">{{ $product->category->name ?? 'Uncategorised' }}</td>
                                <td class="p-2 text-right font-semibold">{{ number_format($here) }}</td>
                                {{-- The group total next to the branch figure on purpose: it is
                                     the sum of every branch's, and seeing both is what makes
                                     "we have some, just not here" obvious. --}}
                                <td class="p-2 text-right text-gray-500 dark:text-gray-400">
                                    {{ number_format((int) $product->stock_quantity) }}
                                </td>
                                <td class="p-2 text-right">
                                    {{ money($here * (float) $product->cost_price) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                    Nothing on the shelves here yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $stock->links() }}</div>
        </div>
    </div>
@endsection
