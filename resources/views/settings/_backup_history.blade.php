{{--
    One backup run per row. Shared by the Backup tab (latest few) and the full
    history page, so the two can never disagree about what a failed run looks
    like. Expects $backups.
--}}
<div class="overflow-x-auto">
    <table class="w-full text-left text-sm dark:text-gray-200">
        <thead>
            <tr class="bg-gray-100 dark:bg-gray-700">
                <th class="p-2">When</th>
                <th class="p-2">File</th>
                <th class="p-2">Where</th>
                <th class="p-2 text-right">Size</th>
                <th class="p-2">Run by</th>
                <th class="p-2">Status</th>
                <th class="p-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($backups as $backup)
                <tr class="border-b border-gray-100 dark:border-gray-700 align-top">
                    <td class="p-2 whitespace-nowrap">{{ $backup->created_at->format('d M Y H:i') }}</td>
                    <td class="p-2 break-all">{{ $backup->filename }}</td>
                    <td class="p-2 text-xs text-gray-500 dark:text-gray-400 break-all">
                        {{ $backup->disk === \App\Services\BackupService::LOCAL ? $backup->path : $backup->disk . ':' . $backup->path }}
                    </td>
                    <td class="p-2 text-right whitespace-nowrap">{{ $backup->size_for_humans }}</td>
                    <td class="p-2">
                        {{-- No user means the scheduler, not a missing record. --}}
                        {{ $backup->creator->name ?? 'Scheduled' }}
                    </td>
                    <td class="p-2">
                        @if ($backup->succeeded())
                            <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800
                                         dark:bg-green-900 dark:text-green-200">Success</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800
                                         dark:bg-red-900 dark:text-red-200">Failed</span>
                            <p class="text-xs text-red-600 dark:text-red-300 mt-1 max-w-sm break-words">
                                {{ $backup->error }}
                            </p>
                        @endif
                    </td>
                    <td class="p-2 text-right whitespace-nowrap">
                        @if ($backup->succeeded())
                            <a href="{{ route('settings.backups.download', $backup) }}"
                                class="text-blue-600 dark:text-blue-400 hover:underline">Download</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="p-3 text-gray-500 dark:text-gray-400">
                        No backups yet. Run one now, or switch on automatic backups above.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
