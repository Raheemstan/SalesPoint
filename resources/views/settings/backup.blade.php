@php
    /*
    | The backup buttons and history. Each of these is its own <form>, which is
    | why this panel sits outside the settings form rather than inside it.
    */
    $primary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $secondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';

    // The route still names the database table; the label uses the trade's noun.
    $exports = [
        'products' => term('product', true),
        'sales' => term('sale', true),
        'users' => 'Users',
    ];
@endphp

<div class="space-y-4">
    <x-card title="Back up now" subtitle="A copy taken straight away, outside the schedule.">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            A kept backup stays in the configured location until retention removes it. The one-off
            download hands you a file and keeps nothing.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <form action="{{ route('settings.backups.run') }}" method="POST">
                @csrf
                <button type="submit" class="{{ $primary }}">
                    <x-icon name="database" size="4" />
                    Run a backup now
                </button>
            </form>

            <form action="{{ route('settings.backup.download') }}" method="POST">
                @csrf
                <button type="submit" class="{{ $secondary }}">
                    <x-icon name="download" size="4" />
                    Download a copy without keeping one
                </button>
            </form>
        </div>
    </x-card>

    <x-card title="Recent backups" subtitle="The last ten runs, by hand or by the scheduler." :padding="false">
        <x-slot:actions>
            <a href="{{ route('settings.backups') }}"
               class="inline-flex min-h-touch items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                See the full history
                <x-icon name="chevron-right" size="4" />
            </a>
        </x-slot:actions>

        @include('settings._backup_history', ['backups' => $backups])
    </x-card>

    <x-card title="Export individual tables" subtitle="One table as a spreadsheet, for an accountant or a stock count.">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            The users export carries names, emails and roles only - never passwords or two-factor
            secrets.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            @foreach ($exports as $table => $text)
                <a href="{{ route('settings.backup.export', $table) }}" class="{{ $secondary }}">
                    <x-icon name="download" size="4" />
                    Export {{ $text }}
                </a>
            @endforeach
        </div>
    </x-card>
</div>
