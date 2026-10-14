{{--
    One backup run per row. Shared by the Backup tab (latest few) and the full
    history page, so the two can never disagree about what a failed run looks
    like. Expects $backups.
--}}

<x-table>
    <x-slot:head>
        <tr>
            <th>When</th>
            <th>File</th>
            <th>Where</th>
            <th class="text-right">Size</th>
            <th>Run by</th>
            <th>Status</th>
            <th><span class="sr-only">Download</span></th>
        </tr>
    </x-slot:head>

    @forelse ($backups as $backup)
        <tr class="align-top">
            <td class="whitespace-nowrap">{{ $backup->created_at->format('d M Y H:i') }}</td>
            <td class="break-all font-medium">{{ $backup->filename }}</td>
            <td class="break-all text-xs text-gray-500 dark:text-gray-400">
                {{ $backup->disk === \App\Services\BackupService::LOCAL ? $backup->path : $backup->disk . ':' . $backup->path }}
            </td>
            <td class="whitespace-nowrap text-right tabular-nums">{{ $backup->size_for_humans }}</td>
            <td class="whitespace-nowrap">
                {{-- No user means the scheduler, not a missing record. --}}
                @if ($backup->creator)
                    {{ $backup->creator->name }}
                @else
                    <span class="text-gray-500 dark:text-gray-400">Scheduled</span>
                @endif
            </td>
            <td>
                @if ($backup->succeeded())
                    <x-badge variant="green" size="sm" dot>Success</x-badge>
                @else
                    <x-badge variant="red" size="sm" dot>Failed</x-badge>
                    <p class="mt-1 max-w-sm break-words text-xs text-red-600 dark:text-red-400">
                        {{ $backup->error }}
                    </p>
                @endif
            </td>
            <td class="whitespace-nowrap text-right">
                @if ($backup->succeeded())
                    <a href="{{ route('settings.backups.download', $backup) }}"
                       class="inline-flex min-h-touch items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
                        <x-icon name="download" size="4" />
                        Download
                    </a>
                @endif
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="7">
                <x-empty icon="database" title="No backups yet"
                         message="Every run appears here, whether it was started by hand or by the scheduler." />
            </td>
        </tr>
    @endforelse
</x-table>
