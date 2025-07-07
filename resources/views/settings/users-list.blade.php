
{{-- Users List --}}
<h3 class="text-xl font-semibold mb-2 text-gray-700 dark:text-gray-200">Existing Users</h3>

<table class="w-full table-auto border-collapse text-sm dark:text-gray-200">
    <thead>
        <tr>
            <th class="border-b p-2 text-left">Name</th>
            <th class="border-b p-2 text-left">Email</th>
            <th class="border-b p-2 text-left">Role</th>
            <th class="border-b p-2 text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($users as $user)
            <tr>
                <td class="border-b p-2">{{ $user->name }}</td>
                <td class="border-b p-2">{{ $user->email }}</td>
                <td class="border-b p-2 capitalize">{{ $user->role }}</td>
                <td class="border-b p-2 text-right">
                    <form action="{{ route('settings.users.delete', $user->id) }}" method="POST"
                        onsubmit="return confirm('Delete this user?');">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-600 hover:underline">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>