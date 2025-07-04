{{-- Create or Edit Form --}}
<form action="{{ route('settings.users.create') }}" method="POST" class="mb-6">
    @csrf
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input name="name" label="Name" value="{{ old('name') }}" />
        <x-input name="email" label="Email" value="{{ old('email') }}" type="email" />
        <x-input name="password" label="Password" type="password" />
        <x-input name="password_confirmation" label="Confirm Password" type="password" />
        <x-select name="role" label="Role" :options="['admin' => 'Admin', 'cashier' => 'Cashier']"
            selected="{{ old('role', 'cashier') }}" />
    </div>

    <div class="mt-4 text-right">
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
            Save User
        </button>
    </div>
</form>

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