@extends('layouts.app')

@section('title', 'Held ' . term('sale', true))

@section('content')
    @php
        /*
        | Held carts.
        |
        | A cashier sees their own; a manager sees everyone's, because somebody has
        | to be able to clear up a cart abandoned on a Friday. Only the cashier who
        | parked a cart may resume it - resuming loads it into *your* till, and the
        | sale that comes out is rung up under your name and against your drawer
        | (see ParkedSalePolicy).
        |
        | Nothing here is reserved stock. The figures are the snapshot taken when
        | the cart was parked; prices and availability are re-read on resume, which
        | says plainly what has moved since.
        */
        $sale = strtolower(term('sale'));
    @endphp

    <x-page-header :title="'Held ' . term('sale', true)"
                   :subtitle="$ownOnly
                       ? 'The ' . strtolower(term('sale', true)) . ' you have put aside.'
                       : 'Every ' . $sale . ' put aside, by any ' . strtolower(term('cashier', false, 'cashier')) . '.'">
        <x-slot:actions>
            <a href="{{ route('pos.index') }}"
               class="flex min-h-touch items-center gap-1.5 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                <x-icon name="cart" size="4" /> Back to the till
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($parked->isEmpty())
        <x-card>
            <x-empty icon="pause" title="Nothing is being held"
                     :message="'Put a ' . $sale . ' aside from the till with Hold (F4) when a '
                         . strtolower(term('customer')) . ' needs to step away.'">
                <x-slot:action>
                    <a href="{{ route('pos.index') }}"
                       class="flex min-h-touch items-center gap-1.5 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                        <x-icon name="cart" size="4" /> Open the till
                    </a>
                </x-slot:action>
            </x-empty>
        </x-card>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($parked as $hold)
                <x-card>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-base font-bold text-gray-900 dark:text-gray-100">
                                {{ $hold->reference }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $hold->created_at->diffForHumans() }}
                                @unless ($ownOnly)
                                    · {{ $hold->user->name ?? 'unknown' }}
                                @endunless
                            </p>
                        </div>

                        <p class="shrink-0 text-right text-lg font-bold text-gray-900 dark:text-gray-100">
                            {{ money($hold->total) }}
                        </p>
                    </div>

                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $hold->item_count }} {{ Str::plural('item', $hold->item_count) }}
                        @if ($hold->customer)
                            · <span class="font-medium">{{ $hold->customer->name }}</span>
                        @endif
                    </p>

                    @if ($hold->note)
                        <p class="mt-1 rounded-lg bg-gray-50 p-2 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ $hold->note }}
                        </p>
                    @endif

                    <div class="mt-3 flex items-center gap-2">
                        @can('resume', $hold)
                            <form method="POST" action="{{ route('pos.parked.resume', $hold) }}" class="flex-1">
                                @csrf
                                <button type="submit"
                                        class="flex min-h-touch w-full items-center justify-center gap-1.5 rounded-lg bg-green-600 px-3 text-sm font-semibold text-white hover:bg-green-700">
                                    <x-icon name="cart" size="4" /> Resume
                                </button>
                            </form>
                        @else
                            <p class="flex-1 text-xs text-gray-400 dark:text-gray-500">
                                Only {{ $hold->user->name ?? 'the cashier who held it' }} can resume this.
                            </p>
                        @endcan

                        @can('delete', $hold)
                            <form method="POST" action="{{ route('pos.parked.destroy', $hold) }}"
                                  onsubmit="return confirm('Delete {{ $hold->reference }}? The held {{ $sale }} cannot be recovered.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="Delete {{ $hold->reference }}"
                                        class="flex h-touch w-touch items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950">
                                    <x-icon name="x-circle" size="5" />
                                </button>
                            </form>
                        @endcan
                    </div>
                </x-card>
            @endforeach
        </div>

        <div class="mt-4">{{ $parked->links() }}</div>
    @endif
@endsection
