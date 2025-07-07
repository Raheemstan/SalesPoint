<div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white dark:bg-gray-800 p-6 rounded shadow w-full max-w-md">
        <h2 class="text-lg font-bold mb-4" id="userModalTitle">Add New User</h2>
        <form id="userForm" method="POST" action="{{ route('register') }}">
            @csrf
            <x-input name="name" label="Name" required />
            <x-input name="email" label="Email" type="email" required />
            <x-input name="password" label="Password" type="password" required />
            <x-select name="role" label="Role" :options="['admin' => 'Admin', 'staff' => 'Staff']" />

            <div class="mt-4 flex justify-end gap-2">
                <button type="button" id="closeUserModal"
                    class="px-4 py-2 bg-gray-300 dark:bg-gray-600 rounded">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
            </div>
        </form>
    </div>
</div>