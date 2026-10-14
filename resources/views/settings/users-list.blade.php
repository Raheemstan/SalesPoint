@php
    $me = auth()->id();
    $activeAdmins = $users->filter(fn ($u) => $u->role === 'admin' && $u->isActive())->count();
    $deactivated = $users->filter(fn ($u) => ! $u->isActive())->count();

    // A role is a status, so it gets a pill like every other status in the app.
    $roleVariant = [
        'admin' => 'purple',
        'manager' => 'blue',
        'cashier' => 'teal',
    ];

    $rowAction = 'font-medium text-primary-600 hover:underline dark:text-primary-400';
@endphp

{{--
    Users are deactivated, not deleted. A user's name hangs off every sale,
    shift, purchase and audit entry they ever created, so deleting is the last
    resort and is labelled as such. The guard rails (your own admin role, the
    last admin) are enforced in UserController - the buttons here are hidden or
    disabled to match, so an admin is not invited to click something that will
    be refused.
--}}
<div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
    <x-stat label="Accounts" :value="number_format($users->count())" icon="users" />
    <x-stat label="Active admins" :value="number_format($activeAdmins)" icon="key"
            :hint="$activeAdmins === 1 ? 'the last one cannot be locked out' : null" />
    <x-stat label="Deactivated" :value="number_format($deactivated)" icon="x-circle"
            :hint="$deactivated === 0 ? 'everyone can sign in' : 'history kept, sign-in blocked'" />
</div>

<x-card title="Users" subtitle="Deactivating keeps a person's history and stops them signing in." :padding="false">
    <x-slot:actions>
        <button type="button" id="open-user-modal"
                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
            <x-icon name="plus" size="4" />
            Add User
        </button>
    </x-slot:actions>

    <x-table>
        <x-slot:head>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last signed in</th>
                <th class="text-right">Actions</th>
            </tr>
        </x-slot:head>

        @forelse ($users as $user)
            @php $isMe = $user->id === $me; @endphp
            <tr class="{{ $user->isActive() ? '' : 'opacity-60' }}">
                <td>
                    <span class="font-medium">{{ $user->name }}</span>
                    @if ($isMe)
                        <span class="text-xs text-gray-500 dark:text-gray-400">(you)</span>
                    @endif
                    @if ($user->hasTwoFactorEnabled())
                        <x-badge variant="indigo" size="sm" class="ml-1"
                                 title="Two-factor authentication is on">2FA</x-badge>
                    @endif
                </td>
                <td class="break-all text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                <td>
                    <x-badge :variant="$roleVariant[$user->role] ?? 'gray'" size="sm">
                        {{ ucfirst($user->role) }}
                    </x-badge>
                </td>
                <td>
                    @if ($user->isActive())
                        <x-badge variant="green" size="sm" dot>Active</x-badge>
                    @else
                        <x-badge variant="gray" size="sm" dot>Deactivated</x-badge>
                    @endif
                </td>
                <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                    {{ $user->last_login_at?->format('d M Y H:i') ?? 'Never' }}
                </td>
                <td class="text-right">
                    <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                        <button type="button" class="edit-user {{ $rowAction }}"
                            data-action="{{ route('settings.users.update', $user) }}"
                            data-name="{{ $user->name }}"
                            data-email="{{ $user->email }}"
                            data-role="{{ $user->role }}">Edit</button>

                        <button type="button" class="reset-password {{ $rowAction }}"
                            data-action="{{ route('settings.users.password', $user) }}"
                            data-name="{{ $user->name }}">Reset password</button>

                        @if (!$isMe)
                            <form action="{{ route('settings.users.status', $user) }}" method="POST" class="inline">
                                @csrf
                                <button class="font-medium text-amber-600 hover:underline dark:text-amber-400"
                                    onclick="return confirm('{{ $user->isActive()
                                        ? addslashes($user->name) . ' will no longer be able to sign in. Their history stays. Continue?'
                                        : 'Let ' . addslashes($user->name) . ' sign in again?' }}');">
                                    {{ $user->isActive() ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>

                            <form action="{{ route('settings.users.delete', $user) }}" method="POST" class="inline">
                                @csrf
                                @method('DELETE')
                                <button class="font-medium text-red-600 hover:underline dark:text-red-400"
                                    onclick="return confirm('Deleting removes {{ addslashes($user->name) }} from the user list for good. Deactivating is almost always what you want instead. Delete anyway?');">
                                    Delete
                                </button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-empty icon="users" title="No users yet"
                             message="Everyone who signs in to this till appears here." />
                </td>
            </tr>
        @endforelse
    </x-table>
</x-card>
