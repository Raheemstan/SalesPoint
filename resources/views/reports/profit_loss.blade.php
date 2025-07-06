@extends('layouts.app')
@section('title', 'Profit & Loss Report')

@section('content')
    <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-sm text-sm">

        <form method="GET" class="flex flex-wrap gap-4 mb-6">
            <div>
                <x-input label="From" type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1 w-full"
                    required />
            </div>
            <div>
                <x-input label="To" type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1 w-full"
                    required />
            </div>
            <div class="items-end px-2 py-1">
                <br>
                <button type="submit" class="black bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Filter
                </button>
            </div>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-green-100 dark:bg-green-800 p-4 rounded">
                <p class="font-medium">Total Sales</p>
                <p class="text-lg font-bold">₦{{ number_format($sales, 2) }}</p>
            </div>
            <div class="bg-yellow-100 dark:bg-yellow-800 p-4 rounded">
                <p class="font-medium">Total Purchases</p>
                <p class="text-lg font-bold">₦{{ number_format($purchases, 2) }}</p>
            </div>
            <div class="bg-red-100 dark:bg-red-800 p-4 rounded">
                <p class="font-medium">Total Expenses</p>
                <p class="text-lg font-bold">₦{{ number_format($expenses, 2) }}</p>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-blue-100 dark:bg-blue-800 p-4 rounded">
                <p class="font-medium">Gross Profit</p>
                <p class="text-lg font-bold">₦{{ number_format($grossProfit, 2) }}</p>
            </div>
            <div class="bg-purple-100 dark:bg-purple-800 p-4 rounded">
                <p class="font-medium">Net Profit</p>
                <p class="text-lg font-bold">₦{{ number_format($netProfit, 2) }}</p>
            </div>
        </div>

        {{-- Detailed Transactions Table --}}
        <div class="mt-10">
            <h3 class="text-lg font-bold mb-2">Detailed Transactions</h3>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-100 dark:bg-gray-700">
                            <th class="p-2 border">Type</th>
                            <th class="p-2 border">Reference</th>
                            <th class="p-2 border">Date</th>
                            <th class="p-2 border">Amount (₦)</th>
                            <th class="p-2 border">Note</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        {{-- Sales --}}
                        @foreach ($salesList as $sale)
                            <tr class="bg-green-50 dark:bg-green-900">
                                <td class="p-2 border">Sale</td>
                                <td class="p-2 border">#{{ $sale->invoice_number }}</td>
                                <td class="p-2 border">{{ $sale->created_at->format('Y-m-d') }}</td>
                                <td class="p-2 border">{{ number_format($sale->total_amount, 2) }}</td>
                                <td class="p-2 border">{{ $sale->note ?? '-' }}</td>
                            </tr>
                        @endforeach

                        {{-- Purchases --}}
                        @foreach ($purchaseList as $purchase)
                            <tr class="bg-yellow-50 dark:bg-yellow-900">
                                <td class="p-2 border">Purchase</td>
                                <td class="p-2 border">#{{ $purchase->invoice_number }}</td>
                                <td class="p-2 border">{{ $purchase->purchase_date }}</td>
                                <td class="p-2 border">{{ number_format($purchase->total_amount, 2) }}</td>
                                <td class="p-2 border">{{ $purchase->note ?? '-' }}</td>
                            </tr>
                        @endforeach

                        {{-- Expenses --}}
                        @foreach ($expenseList as $expense)
                            <tr class="bg-red-50 dark:bg-red-900">
                                <td class="p-2 border">Expense</td>
                                <td class="p-2 border">#{{ $expense->id }}</td>
                                <td class="p-2 border">{{ $expense->expense_date }}</td>
                                <td class="p-2 border">{{ number_format($expense->amount, 2) }}</td>
                                <td class="p-2 border">{{ $expense->description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8 flex gap-4">
            <button id="show-bar-chart" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded">
                📊 Show Bar Chart
            </button>
            <button id="show-line-chart" class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded">
                📈 Show Line Chart
            </button>
        </div>

        {{-- Chart containers --}}
        <div id="charts" class="mt-6 space-y-6 hidden">
            <div class="p-4 bg-white dark:bg-gray-800 rounded shadow">
                <canvas id="barChart" class="w-full h-64"></canvas>
            </div>
            <div class="p-4 bg-white dark:bg-gray-800 rounded shadow">
                <canvas id="lineChart" class="w-full h-64"></canvas>
            </div>
        </div>
@endsection
    @section('scripts')
        <script>
            const showLineBtn = document.getElementById('show-line-chart');
            const showBarBtn = document.getElementById('show-bar-chart');
            const chartsWrapper = document.getElementById('charts');

            let barChart, lineChart;
            showBarBtn.addEventListener('click', () => {
                if (lineChart) {
                    lineChart.destroy();
                    lineChart = null;
                }
                chartsWrapper.classList.remove('hidden');

                if (!barChart) {
                    const ctx = document.getElementById('barChart').getContext('2d');
                    barChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: ['Sales', 'Purchases', 'Expenses', 'Gross Profit', 'Net Profit'],
                            datasets: [{
                                label: '₦ Value',
                                data: [{{ $sales }}, {{ $purchases }}, {{ $expenses }}, {{ $grossProfit }}, {{ $netProfit }}],
                                backgroundColor: [
                                    '#4ade80', // green
                                    '#facc15', // yellow
                                    '#f87171', // red
                                    '#60a5fa', // blue
                                    '#c084fc'  // purple
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: function (context) {
                                            return '₦' + parseFloat(context.raw).toLocaleString();
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function (value) {
                                            return '₦' + value.toLocaleString();
                                        }
                                    }
                                }
                            },
                            onClick: (e, elements) => {
                                if (elements.length > 0) {
                                    const index = elements[0].index;
                                    const label = barChart.data.labels[index];
                                    const value = barChart.data.datasets[0].data[index];

                                    // Optional: Redirect or open modal
                                    // if (label === 'Sales') window.location.href = '/reports/sales?from=...&to=...';
                                }
                            }
                        }
                    });
                }
            });

            showLineBtn.addEventListener('click', () => {
                chartsWrapper.classList.remove('hidden');
                if (barChart) {
                    barChart.destroy();
                    barChart = null;
                }

                const ctx = document.getElementById('lineChart').getContext('2d');
                lineChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: {!! json_encode($labels) !!},
                        datasets: [
                            {
                                label: 'Sales',
                                data: {!! json_encode($salesData) !!},
                                borderColor: '#4ade80',
                                backgroundColor: '#4ade80',
                                fill: false,
                                tension: 0.3
                            },
                            {
                                label: 'Purchases',
                                data: {!! json_encode($purchasesData) !!},
                                borderColor: '#facc15',
                                backgroundColor: '#facc15',
                                fill: false,
                                tension: 0.3
                            },
                            {
                                label: 'Expenses',
                                data: {!! json_encode($expensesData) !!},
                                borderColor: '#f87171',
                                backgroundColor: '#f87171',
                                fill: false,
                                tension: 0.3
                            },
                            {
                                label: 'Net Profit',
                                data: {!! json_encode($netProfitData) !!},
                                borderColor: '#3b82f6',
                                backgroundColor: '#3b82f6',
                                fill: false,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    label: function (context) {
                                        return context.dataset.label + ': ₦' + context.formattedValue;
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: 'Profit & Loss Over Time'
                            }
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        },
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Date'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Amount (₦)'
                                }
                            }
                        },
                        onClick: (e, elements) => {
                            if (elements.length > 0) {
                                const index = elements[0].index;
                                const selectedDate = {!! json_encode($labels) !!}[index];
                                alert('Show details for: ' + selectedDate);
                                // Optionally fetch and show modal or table here
                            }
                        }
                    }
                });
            });
        </script>
    @endsection