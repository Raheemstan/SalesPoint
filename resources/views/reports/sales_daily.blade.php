@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded p-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
            <div class="bg-blue-600 text-white rounded p-5">
                <div class="text-sm">Today's Sales</div>
                <div class="text-2xl font-bold">₦{{ number_format($data['sales_today'], 2) }}</div>
            </div>

            <div class="bg-green-600 text-white rounded p-5">
                <div class="text-sm">This Month's Sales</div>
                <div class="text-2xl font-bold">₦{{ number_format($data['sales_this_month'], 2) }}</div>
            </div>

            <div class="bg-yellow-500 text-white rounded p-5">
                <div class="text-sm">Transactions Today</div>
                <div class="text-2xl font-bold">{{ $data['transactions_today'] }}</div>
            </div>

            <div class="bg-gray-700 text-white rounded p-5">
                <div class="text-sm">Total Products</div>
                <div class="text-2xl font-bold">{{ $data['total_products'] }}</div>
            </div>
        </div>
    </div>
@endsection