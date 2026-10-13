{{--
    The backup buttons and history. Each of these is its own <form>, which is
    why this panel sits outside the settings form rather than inside it.
--}}
<div class="space-y-4">

    <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
        <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-1">Back up now</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            Takes a copy straight away and keeps it in the configured location, where it stays until
            retention removes it.
        </p>

        <div class="flex flex-wrap gap-3">
            <form action="{{ route('settings.backups.run') }}" method="POST">
                @csrf
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Run a backup now
                </button>
            </form>

            <form action="{{ route('settings.backup.download') }}" method="POST">
                @csrf
                <button type="submit"
                    class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded">
                    Download a copy without keeping one
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold text-gray-700 dark:text-gray-200">Recent backups</h3>
            <a href="{{ route('settings.backups') }}" class="text-blue-600 dark:text-blue-400 hover:underline text-xs">
                See the full history &rarr;
            </a>
        </div>

        @include('settings._backup_history', ['backups' => $backups])
    </div>

    <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
        <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-1">Export individual tables</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            A spreadsheet of one table, for an accountant or a stock count. The users export carries
            names, emails and roles only - never passwords or two-factor secrets.
        </p>
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
