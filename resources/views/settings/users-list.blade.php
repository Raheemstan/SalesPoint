@php
    $me = auth()->id();
    $activeAdmins = $users->filter(fn ($u) => $u->role === 'admin' && $u->isActive())->count();
@endphp

{{--
    Users are deactivated, not deleted. A user's name hangs off every sale,
    shift, purchase and audit entry they ever created, so deleting is the last
    resort and is labelled as such. The guard rails (your own admin role, the
    last admin) are enforced in UserController - the buttons here are hidden or
    disabled to match, so an admin is not invited to click something that will
    be refused.
--}}
<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
    <div>
        <h3 class="text-xl font-semibold text-gray-700 dark:text-gray-200">Users</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ $users->count() }} {{ Str::plural('account', $users->count()) }},
            {{ $activeAdmins }} active {{ Str::plural('admin', $activeAdmins) }}.
            Deactivating keeps a person's history and stops them signing in.
        </p>
    </div>
    <button type="button" id="open-user-modal" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        + Add User
    </button>
</div>

<div class="overflow-x-auto">
    <table class="w-full table-auto border-collapse text-sm dark:text-gray-200">
        <thead>
            <tr class="bg-gray-100 dark:bg-gray-700">
                <th class="p-2 text-left">Name</th>
                <th class="p-2 text-left">Email</th>
                <th class="p-2 text-left">Role</th>
                <th class="p-2 text-left">Status</th>
                <th class="p-2 text-left">Last signed in</th>
                <th class="p-2 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                @php $isMe = $user->id === $me; @endphp
                <tr class="border-b border-gray-100 dark:border-gray-700 {{ $user->isActive() ? '' : 'opacity-60' }}">
                    <td class="p-2">
                        {{ $user->name }}
                        @if ($isMe)
                            <span class="text-xs text-gray-400">(you)</span>
                        @endif
                        @if ($user->hasTwoFactorEnabled())
                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-indigo-100 text-indigo-800
                                         dark:bg-indigo-900 dark:text-indigo-200" title="Two-factor authentication is on">2FA</span>
                        @endif
                    </td>
                    <td class="p-2 break-all">{{ $user->email }}</td>
                    <td class="p-2 capitalize">{{ $user->role }}</td>
                    <td class="p-2">
                        @if ($user->isActive())
                            <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800
                                         dark:bg-green-900 dark:text-green-200">Active</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-700
                                         dark:bg-gray-600 dark:text-gray-200">Deactivated</span>
                        @endif
                    </td>
                    <td class="p-2 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        {{ $user->last_login_at?->format('d M Y H:i') ?? 'Never' }}
                    </td>
                    <td class="p-2 text-right whitespace-nowrap space-x-2">
                        <button type="button"
                            class="edit-user text-blue-600 dark:text-blue-400 hover:underline"
                            data-action="{{ route('settings.users.update', $user) }}"
                            data-name="{{ $user->name }}"
                            data-email="{{ $user->email }}"
                            data-role="{{ $user->role }}">Edit</button>

                        <button type="button"
                            class="reset-password text-blue-600 dark:text-blue-400 hover:underline"
                            data-action="{{ route('settings.users.password', $user) }}"
                            data-name="{{ $user->name }}">Reset password</button>

                        @if (!$isMe)
                            <form action="{{ route('settings.users.status', $user) }}" method="POST" class="inline">
                                @csrf
                                <button class="text-amber-600 dark:text-amber-400 hover:underline"
                                    onclick="return confirm('{{ $user->isActive()
                                        ? addslashes($user->name) . ' will no longer be able to sign in. Their history stays. Continue?'
                                        : 'Let ' . addslashes($user->name) . ' sign in again?' }}');">
                                    {{ $user->isActive() ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>

                            <form action="{{ route('settings.users.delete', $user) }}" method="POST" class="inline">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600 hover:underline"
                                    onclick="return confirm('Deleting removes {{ addslashes($user->name) }} from the user list for good. Deactivating is almost always what you want instead. Delete anyway?');">
                                    Delete
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
