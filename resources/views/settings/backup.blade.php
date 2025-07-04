<div>
    <div class="space-y-4">

        {{-- Database Backup --}}
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow">
            <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-2">Full Database Backup</h3>
            <form action="{{ route('settings.backup.download') }}" method="POST">
                @csrf
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    📦 Download .SQL Backup
                </button>
            </form>
        </div>

        {{-- CSV Exports --}}
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow">
            <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-2">Export Individual Tables</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach (['products', 'sales', 'users'] as $table)
                    <a href="{{ route('settings.backup.export', $table) }}"
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-center block">
                        Export {{ ucfirst($table) }}
                    </a>
                @endforeach
            </div>
        </div>

    </div>
</div>
