@php
    $overlay = 'fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4';
    $panel = 'bg-white dark:bg-gray-800 p-6 rounded shadow w-full max-w-md';
@endphp

{{--
    Three modals, one per job: create, edit, reset password. Each is a separate
    top-level <form> - they are rendered here, outside the settings form and
    outside the users table, because a form inside a form is invalid HTML and a
    form inside a table cell is fragile.

    Plain ids and one small script rather than Alpine: the ids are what the
    regression test for this screen checks, and they were the original bug
    (the script looked up ids that did not exist, so the page threw on load and
    no modal ever opened).
--}}

{{-- Create --}}
<div id="userModal" class="{{ $overlay }}">
    <div class="{{ $panel }}">
        <h2 class="text-lg font-bold mb-4 text-gray-800 dark:text-gray-100">Add a user</h2>

        <form id="userForm" method="POST" action="{{ route('settings.users.create') }}">
            @csrf
            <x-input name="name" label="Name" required />
            <x-input name="email" label="Email" type="email" required />
            <x-input name="password" label="Password" type="password" required autocomplete="new-password" />
            <x-input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
            <x-select name="role" label="Role" value="cashier"
                :options="['cashier' => 'Cashier - the till only', 'manager' => 'Manager - stock, reports, settings', 'admin' => 'Admin - everything, including users']" />

            <div class="mt-4 flex justify-end gap-2">
                <button type="button" id="closeUserModal"
                    class="px-4 py-2 bg-gray-300 dark:bg-gray-600 dark:text-gray-100 rounded">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">Create user</button>
            </div>
        </form>
    </div>
</div>

{{-- Edit --}}
<div id="editUserModal" class="{{ $overlay }}">
    <div class="{{ $panel }}">
        <h2 class="text-lg font-bold mb-4 text-gray-800 dark:text-gray-100">
            Edit <span id="editUserName"></span>
        </h2>

        <form id="editUserForm" method="POST" action="">
            @csrf
            @method('PUT')
            <x-input name="name" id="edit_name" label="Name" required />
            <x-input name="email" id="edit_email" label="Email" type="email" required />
            <x-select name="role" id="edit_role" label="Role"
                :options="['cashier' => 'Cashier', 'manager' => 'Manager', 'admin' => 'Admin']" />

            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                You cannot remove your own admin role, and the last admin cannot be demoted - there
                would be nobody left who could undo it.
            </p>

            <div class="mt-4 flex justify-end gap-2">
                <button type="button" id="closeEditUserModal"
                    class="px-4 py-2 bg-gray-300 dark:bg-gray-600 dark:text-gray-100 rounded">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">Save changes</button>
            </div>
        </form>
    </div>
</div>

{{-- Reset password --}}
<div id="resetPasswordModal" class="{{ $overlay }}">
    <div class="{{ $panel }}">
        <h2 class="text-lg font-bold mb-1 text-gray-800 dark:text-gray-100">
            New password for <span id="resetUserName"></span>
        </h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            For someone who is locked out. They are signed out everywhere else, and the change is
            recorded in the audit log - the password itself never is.
        </p>

        <form id="resetPasswordForm" method="POST" action="">
            @csrf
            <x-input name="password" id="reset_password" label="New password" type="password" required autocomplete="new-password" />
            <x-input name="password_confirmation" id="reset_password_confirmation" label="Confirm new password"
                type="password" required autocomplete="new-password" />

            <div class="mt-4 flex justify-end gap-2">
                <button type="button" id="closeResetPasswordModal"
                    class="px-4 py-2 bg-gray-300 dark:bg-gray-600 dark:text-gray-100 rounded">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">Set password</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const show = el => el && el.classList.remove('hidden');
        const hide = el => el && el.classList.add('hidden');

        // Create
        const userModal = document.getElementById('userModal');
        const openUserModal = document.getElementById('open-user-modal');
        if (userModal && openUserModal) {
            openUserModal.addEventListener('click', () => {
                document.getElementById('userForm').reset();
                show(userModal);
            });
            document.getElementById('closeUserModal').addEventListener('click', () => hide(userModal));
        }

        // Edit: the row's buttons carry the values and the per-user action URL,
        // so there is no lookup and no second request to populate the form.
        const editModal = document.getElementById('editUserModal');
        document.querySelectorAll('.edit-user').forEach(button => {
            button.addEventListener('click', () => {
                document.getElementById('editUserForm').action = button.dataset.action;
                document.getElementById('edit_name').value = button.dataset.name;
                document.getElementById('edit_email').value = button.dataset.email;
                document.getElementById('edit_role').value = button.dataset.role;
                document.getElementById('editUserName').textContent = button.dataset.name;
                show(editModal);
            });
        });
        document.getElementById('closeEditUserModal').addEventListener('click', () => hide(editModal));

        // Reset password
        const resetModal = document.getElementById('resetPasswordModal');
        document.querySelectorAll('.reset-password').forEach(button => {
            button.addEventListener('click', () => {
                const form = document.getElementById('resetPasswordForm');
                form.reset();
                form.action = button.dataset.action;
                document.getElementById('resetUserName').textContent = button.dataset.name;
                show(resetModal);
            });
        });
        document.getElementById('closeResetPasswordModal').addEventListener('click', () => hide(resetModal));

        // Escape closes whichever is open.
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                [userModal, editModal, resetModal].forEach(hide);
            }
        });
    })();
</script>
