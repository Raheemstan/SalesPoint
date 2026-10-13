@extends('layouts.app')
@section('title', $tableOrder->table->name . ' · ' . $tableOrder->reference)

@php
    /*
    |--------------------------------------------------------------------------
    | The order screen - where a server spends the whole shift
    |--------------------------------------------------------------------------
    |
    | Held in one hand, tapped with a thumb, often in low light. So: the bill on
    | the left where it can be read out to the guests, the menu on the right as
    | big labelled buttons, and every action on this one screen - there is no
    | room on a tablet for a page per verb.
    |
    | Adding uses a single form with one submit button per menu item (a button
    | carries its own name/value), rather than a form per item. With 200 menu
    | items that is the difference between one form and two hundred.
    |
    | Lines the kitchen has seen are marked and locked: see TableOrderService
    | ::updateItem() for why a cooked plate cannot be quietly edited.
    */
    $server = term('cashier');
    $guestWord = fn (int $n) => lcfirst(term('customer', $n !== 1));
    $open = $tableOrder->isOpen();
    $pending = $tableOrder->items->whereNull('sent_to_kitchen_at');
    $taxRate = (float) setting('tax_rate', 0);

    $btn = 'inline-flex min-h-touch items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-200';
    $input = 'mt-1 block w-full min-h-touch rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
@endphp

@section('content')
    <x-page-header :title="$tableOrder->table->name"
                   :back="route('tables.index')"
                   :backLabel="term('table', true, 'Tables')"
                   :subtitle="$tableOrder->reference . ' · ' . $tableOrder->guests . ' ' . $guestWord($tableOrder->guests)
                        . ' · ' . $server . ': ' . ($tableOrder->user->name ?? '-')
                        . ($open ? ' · seated ' . $tableOrder->seatedFor() : '')">
        <x-slot:actions>
            @if ($open)
                <x-badge variant="amber" dot size="lg">Open</x-badge>
            @elseif ($tableOrder->isSettled())
                <x-badge variant="green" dot size="lg">Settled</x-badge>
            @else
                <x-badge variant="gray" dot size="lg">Cancelled</x-badge>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (!$open)
        <div class="mb-4 rounded-lg bg-gray-100 p-4 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
            This {{ lcfirst(term('sale')) }} is closed, so it can no longer be changed.
            @if ($tableOrder->sale)
                It was paid for as
                <a href="{{ route('sales.show', $tableOrder->sale) }}" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">
                    {{ $tableOrder->sale->invoice_number }}</a>.
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">

        {{-- ---------------------------------------------------------------- --}}
        {{-- The bill                                                          --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="space-y-4 lg:col-span-2">
            <x-card :title="term('sale')" :padding="false">
                @if ($tableOrder->note)
                    <p class="border-b border-gray-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-gray-700 dark:bg-amber-900/20 dark:text-amber-200">
                        {{ $tableOrder->note }}
                    </p>
                @endif

                @if ($tableOrder->items->isEmpty())
                    <x-empty icon="receipt" title="Nothing ordered yet"
                             :message="'Tap the ' . lcfirst(term('product', true)) . ' on the right to start the ' . lcfirst(term('sale')) . '.'" />
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($tableOrder->items as $item)
                            <li class="px-3 py-2.5">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            <span class="tabular-nums">{{ $item->quantity }}</span>
                                            &times; {{ $item->product->name ?? 'Removed item' }}
                                        </p>

                                        <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="tabular-nums">{{ money($item->price) }} each</span>
                                            @if ($item->course)
                                                <x-badge variant="blue" size="sm">{{ ucfirst($item->course) }}</x-badge>
                                            @endif
                                            @if ($item->isSent())
                                                <x-badge variant="teal" size="sm">
                                                    Kitchen {{ $item->sent_to_kitchen_at->format('H:i') }}
                                                </x-badge>
                                            @else
                                                <x-badge variant="gray" size="sm">Not sent</x-badge>
                                            @endif
                                        </p>

                                        @if ($item->note)
                                            <p class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">{{ $item->note }}</p>
                                        @endif
                                    </div>

                                    <p class="shrink-0 text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                        {{ money($item->gross) }}
                                    </p>
                                </div>

                                @if ($open)
                                    <div class="mt-2 flex items-center gap-1.5">
                                        @unless ($item->isSent())
                                            {{-- Quantity: two buttons, each posting the figure it means. --}}
                                            <form method="POST" action="{{ route('table-orders.items.update', [$tableOrder, $item]) }}"
                                                  class="flex items-center gap-1.5">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="note" value="{{ $item->note }}">
                                                <input type="hidden" name="course" value="{{ $item->course }}">

                                                <button name="quantity" value="{{ $item->quantity - 1 }}"
                                                        @disabled($item->quantity <= 1)
                                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-700
                                                               hover:bg-gray-200 disabled:opacity-40 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        aria-label="One fewer">
                                                    <x-icon name="minus" size="4" />
                                                </button>

                                                <button name="quantity" value="{{ $item->quantity + 1 }}"
                                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-700
                                                               hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        aria-label="One more">
                                                    <x-icon name="plus" size="4" />
                                                </button>
                                            </form>

                                            {{-- Unsent: a typo, so it just goes. --}}
                                            <form method="POST" action="{{ route('table-orders.items.destroy', [$tableOrder, $item]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-red-600
                                                               hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                                                    <x-icon name="x" size="4" />
                                                    Remove
                                                </button>
                                            </form>
                                        @else
                                            {{--
                                                Sent: the food exists. Removing it is a write-off,
                                                so it asks why and the answer is audited.
                                            --}}
                                            <button type="button"
                                                    @click="$dispatch('void-line', {
                                                        url: '{{ route('table-orders.items.destroy', [$tableOrder, $item]) }}',
                                                        name: @js(($item->quantity . ' x ' . ($item->product->name ?? 'item')))
                                                    })"
                                                    class="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-red-600
                                                           hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                                                <x-icon name="alert-triangle" size="4" />
                                                Remove with reason
                                            </button>
                                        @endunless
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-700">
                        <div class="flex justify-between text-gray-600 dark:text-gray-300">
                            <span>Subtotal</span>
                            <span class="tabular-nums">{{ money($totals['subtotal']) }}</span>
                        </div>
                        @if ($totals['tax'] > 0)
                            <div class="mt-1 flex justify-between text-gray-600 dark:text-gray-300">
                                <span>Tax ({{ rtrim(rtrim(number_format($taxRate, 2), '0'), '.') }}%)</span>
                                <span class="tabular-nums">{{ money($totals['tax']) }}</span>
                            </div>
                        @endif
                        <div class="mt-2 flex items-baseline justify-between border-t border-gray-200 pt-2 dark:border-gray-700">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">Total</span>
                            <span class="text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
                                {{ money($totals['grand_total']) }}
                            </span>
                        </div>
                    </div>
                @endif
            </x-card>

            @if ($open)
                {{-- Settle. The money is taken at the till; this only hands it over. --}}
                <x-card>
                    <form method="POST" action="{{ route('table-orders.settle', $tableOrder) }}"
                          x-data="{
                              type: 'none',
                              value: '',
                              bill: {{ $totals['grand_total'] }},
                              charge() {
                                  const v = Number(this.value);
                                  if (this.type === 'none' || ! v || v < 0) return 0;
                                  return this.type === 'percent' ? this.bill * v / 100 : v;
                              },
                              fmt(n) { return '{{ currency() }}' + Number(n).toFixed({{ (int) setting('currency_decimals', 2) }}); }
                          }">
                        @csrf

                        @if (feature('tips'))
                            {{--
                                A tip or service charge, added at the last moment -
                                which is when a guest decides one. The amount is
                                only previewed here; it is worked out again
                                server-side from the order's own total.
                            --}}
                            <p class="{{ $label }} mb-2">Tip or service charge</p>

                            <div class="flex flex-wrap gap-1.5">
                                @foreach ([['none', '', 'None'], ['percent', '5', '5%'], ['percent', '10', '10%'], ['percent', '12.5', '12.5%']] as [$t, $v, $text])
                                    <button type="button" @click="type = '{{ $t }}'; value = '{{ $v }}'"
                                            :class="type === '{{ $t }}' && value === '{{ $v }}'
                                                ? 'bg-primary-600 text-white'
                                                : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'"
                                            class="min-h-touch rounded-lg px-3 text-sm font-semibold">{{ $text }}</button>
                                @endforeach
                            </div>

                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                    Type
                                    <select name="service_charge_type" x-model="type" class="{{ $input }}">
                                        <option value="none">None</option>
                                        <option value="percent">Percent</option>
                                        <option value="fixed">Fixed amount</option>
                                    </select>
                                </label>

                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="type === 'fixed' ? 'Amount ({{ currency() }})' : 'Percent (%)'">Percent (%)</span>
                                    <input type="number" step="0.01" min="0" name="service_charge_value"
                                           x-model="value" :disabled="type === 'none'"
                                           class="{{ $input }} disabled:opacity-50">
                                </label>
                            </div>

                            <div class="mt-3 flex items-baseline justify-between border-t border-gray-200 pt-2 text-sm dark:border-gray-700"
                                 x-show="charge() > 0" x-cloak>
                                <span class="text-gray-600 dark:text-gray-300">Service</span>
                                <span class="tabular-nums text-gray-900 dark:text-gray-100" x-text="fmt(charge())"></span>
                            </div>
                            <div class="mt-1 flex items-baseline justify-between text-sm" x-show="charge() > 0" x-cloak>
                                <span class="font-semibold text-gray-900 dark:text-gray-100">To pay</span>
                                <span class="text-xl font-bold tabular-nums text-gray-900 dark:text-gray-100"
                                      x-text="fmt(bill + charge())"></span>
                            </div>
                        @endif

                        <button class="{{ $btn }} mt-3 w-full bg-green-600 text-white hover:bg-green-700 focus:ring-green-500"
                                @disabled($tableOrder->items->isEmpty())>
                            <x-icon name="coins" size="5" />
                            Settle at the till
                        </button>

                        <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                            Opens the till with this {{ lcfirst(term('sale')) }} loaded. Payment is taken there.
                        </p>
                    </form>
                </x-card>
            @endif
        </div>

        {{-- ---------------------------------------------------------------- --}}
        {{-- Actions and the menu                                              --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="space-y-4 lg:col-span-3">
            @if ($open)
                <x-card>
                    <div class="flex flex-wrap gap-2">
                        @if (feature('kitchen'))
                            <form method="POST" action="{{ route('table-orders.kitchen', $tableOrder) }}">
                                @csrf
                                <button class="{{ $btn }} bg-primary-600 text-white hover:bg-primary-700 focus:ring-primary-500"
                                        @disabled($pending->isEmpty())>
                                    <x-icon name="receipt" size="5" />
                                    Send to kitchen
                                    @if ($pending->isNotEmpty())
                                        <span class="rounded-full bg-white/20 px-2 text-xs tabular-nums">{{ $pending->count() }}</span>
                                    @endif
                                </button>
                            </form>
                        @endif

                        @if ($freeTables->isNotEmpty())
                            <button type="button" @click="$dispatch('open-modal', 'transfer-order')"
                                    class="{{ $btn }} bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-400
                                           dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                <x-icon name="refresh" size="5" />
                                Move {{ lcfirst(term('table', false, 'table')) }}
                            </button>
                        @endif

                        @can('delete', $tableOrder)
                            <button type="button" @click="$dispatch('open-modal', 'cancel-order')"
                                    class="{{ $btn }} bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-400
                                           dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50">
                                <x-icon name="x-circle" size="5" />
                                Cancel {{ lcfirst(term('sale')) }}
                            </button>
                        @endcan
                    </div>

                    @if (feature('kitchen') && $pending->isEmpty() && $tableOrder->items->isNotEmpty())
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Everything on this {{ lcfirst(term('sale')) }} has been sent to the kitchen.
                        </p>
                    @endif
                </x-card>

                {{-- The menu. One form, one submit button per item. --}}
                <form method="POST" action="{{ route('table-orders.items.store', $tableOrder) }}">
                    @csrf
                    <input type="hidden" name="quantity" value="1">

                    <div class="space-y-4">
                        @forelse ($menu as $section => $products)
                            <x-card :title="$section" :padding="false">
                                <div class="grid grid-cols-2 gap-2 p-3 sm:grid-cols-3">
                                    @foreach ($products as $product)
                                        <div class="relative">
                                            <button name="product_id" value="{{ $product->id }}"
                                                    class="flex min-h-touch w-full flex-col items-start gap-0.5 rounded-lg bg-gray-50 p-2.5 pr-9 text-left
                                                           ring-1 ring-gray-200 hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-primary-500
                                                           dark:bg-gray-700/50 dark:ring-gray-600 dark:hover:bg-primary-900/30">
                                                <span class="line-clamp-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                    {{ $product->name }}
                                                </span>
                                                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                                    {{ money($product->sale_price) }}
                                                </span>
                                            </button>

                                            {{--
                                                Notes and courses: a second, always-visible
                                                control rather than a long-press, which
                                                nobody discovers and no keyboard can reach.
                                            --}}
                                            <button type="button"
                                                    @click="$dispatch('detail-line', {
                                                        id: {{ $product->id }},
                                                        name: @js($product->name),
                                                        price: @js(money($product->sale_price))
                                                    })"
                                                    class="absolute right-1 top-1 inline-flex h-8 w-8 items-center justify-center rounded-lg
                                                           text-gray-400 hover:bg-white hover:text-gray-700 focus:outline-none focus:ring-2
                                                           focus:ring-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                                                    aria-label="Add {{ $product->name }} with a note">
                                                <x-icon name="sliders" size="4" />
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </x-card>
                        @empty
                            <x-card>
                                <x-empty icon="box" :title="'No ' . lcfirst(term('product', true)) . ' on the menu'"
                                         :message="'A manager needs to add ' . lcfirst(term('product', true)) . ' before an ' . lcfirst(term('sale')) . ' can be taken.'" />
                            </x-card>
                        @endforelse
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if ($open)
        {{-- Add with a note and a course. --}}
        <div x-data="{ item: { id: null, name: '', price: '' } }"
             @detail-line.window="item = $event.detail; $dispatch('open-modal', 'detail-line')">
            <x-modal name="detail-line" maxWidth="sm" focusable>
                <form method="POST" action="{{ route('table-orders.items.store', $tableOrder) }}" class="p-5">
                    @csrf
                    <input type="hidden" name="product_id" :value="item.id">

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="item.name"></h2>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400" x-text="item.price"></p>

                    <div class="mt-4 space-y-3">
                        <label class="{{ $label }}">
                            Quantity
                            <input type="number" name="quantity" value="1" min="1" max="999" required class="{{ $input }} text-lg">
                        </label>

                        <label class="{{ $label }}">
                            Course
                            <select name="course" class="{{ $input }}">
                                <option value="">Not set</option>
                                @foreach (\App\Models\TableOrderItem::COURSES as $course)
                                    <option value="{{ $course }}">{{ ucfirst($course) }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="{{ $label }}">
                            Note for the kitchen
                            <input type="text" name="note" maxlength="255" placeholder="No onions, well done, allergy..."
                                   class="{{ $input }}">
                        </label>
                    </div>

                    <div class="mt-5 flex gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'detail-line')"
                                class="min-h-touch flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700
                                       hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            Cancel
                        </button>
                        <button class="min-h-touch flex-1 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                            Add
                        </button>
                    </div>
                </form>
            </x-modal>
        </div>

        {{-- Remove a line the kitchen has already cooked. --}}
        <div x-data="{ line: { url: '', name: '' } }"
             @void-line.window="line = $event.detail; $dispatch('open-modal', 'void-line')">
            <x-modal name="void-line" maxWidth="sm" focusable>
                <form method="POST" :action="line.url" class="p-5">
                    @csrf
                    @method('DELETE')

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Remove from the kitchen</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-semibold text-gray-700 dark:text-gray-200" x-text="line.name"></span>
                        has already been sent, so it may already be cooked. Say why it is coming off the
                        {{ lcfirst(term('sale')) }} - this is recorded.
                    </p>

                    <label class="{{ $label }} mt-4">
                        Reason
                        <input type="text" name="reason" required maxlength="255"
                               placeholder="Sent back, wrong order, guest changed their mind" class="{{ $input }}">
                    </label>

                    <div class="mt-5 flex gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'void-line')"
                                class="min-h-touch flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700
                                       hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            Keep it
                        </button>
                        <button class="min-h-touch flex-1 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">
                            Remove
                        </button>
                    </div>
                </form>
            </x-modal>
        </div>

        {{-- Move to another free table. --}}
        <x-modal name="transfer-order" maxWidth="sm" focusable>
            <form method="POST" action="{{ route('table-orders.transfer', $tableOrder) }}" class="p-5">
                @csrf

                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Move {{ $tableOrder->table->name }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Only free {{ lcfirst(term('table', true, 'tables')) }} are listed.
                </p>

                <label class="{{ $label }} mt-4">
                    Move to
                    <select name="table_id" required class="{{ $input }}">
                        @foreach ($freeTables as $free)
                            <option value="{{ $free->id }}">
                                {{ $free->name }}{{ $free->area ? ' (' . $free->area . ')' : '' }} · seats {{ $free->seats }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <div class="mt-5 flex gap-2">
                    <button type="button" @click="$dispatch('close-modal', 'transfer-order')"
                            class="min-h-touch flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700
                                   hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        Cancel
                    </button>
                    <button class="min-h-touch flex-1 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                        Move
                    </button>
                </div>
            </form>
        </x-modal>

        @can('delete', $tableOrder)
            <x-modal name="cancel-order" maxWidth="sm" focusable>
                <form method="POST" action="{{ route('table-orders.destroy', $tableOrder) }}" class="p-5">
                    @csrf
                    @method('DELETE')

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Cancel {{ $tableOrder->reference }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Nothing is charged and no stock is moved. Anything already cooked is written off, so
                        say what happened.
                    </p>

                    <label class="{{ $label }} mt-4">
                        Reason
                        <input type="text" name="reason" required minlength="3" maxlength="255"
                               placeholder="Guests left, opened by mistake, walked out" class="{{ $input }}">
                    </label>

                    <div class="mt-5 flex gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'cancel-order')"
                                class="min-h-touch flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700
                                       hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            Keep it open
                        </button>
                        <button class="min-h-touch flex-1 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">
                            Cancel {{ lcfirst(term('sale')) }}
                        </button>
                    </div>
                </form>
            </x-modal>
        @endcan
    @endif
@endsection
