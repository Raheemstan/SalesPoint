@php
    /*
    | Three modals, one per job: create, edit, reset password. Each is a separate
    | top-level <form> - they are rendered here, outside the settings form and
    | outside the users table, because a form inside a form is invalid HTML and a
    | form inside a table cell is fragile.
    |
    | Plain ids and one small script rather than Alpine: the ids are what the
    | regression test for this screen checks, and they were the original bug
    | (the script looked up ids that did not exist, so the page threw on load and
    | no modal ever opened). The classList toggle of `hidden` is the same, so the
    | overlay keeps both `hidden` and `flex`: Tailwind emits `hidden` last, so it
    | wins until the script removes it.
    */
    $overlay = 'fixed inset-0 z-modal flex hidden items-center justify-center bg-gray-900/60 p-4';
    $panel = 'max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-xl'
        . ' ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $heading = 'text-base font-semibold text-gray-900 dark:text-gray-100';

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';

    $primary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $secondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';

    $roles = [
        'cashier' => 'Cashier - the till only',
        'manager' => 'Manager - stock, reports, settings',
        'admin' => 'Admin - everything, including users',
    ];
@endphp

{{-- Create --}}
<div id="userModal" class="{{ $overlay }}" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
    <div class="{{ $panel }}">
        <h2 id="userModalTitle" class="{{ $heading }}">Add a user</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            They can sign in as soon as you save, with the password you set here.
        </p>

        <form id="userForm" method="POST" action="{{ route('settings.users.create') }}" class="mt-4 space-y-3">
            @csrf

            <div>
                <label for="name" class="{{ $label }}">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required class="{{ $control }}">
                @error('name')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="{{ $label }}">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                       autocomplete="off" class="{{ $control }}">
                @error('email')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="{{ $label }}">Password</label>
                <input type="password" id="password" name="password" required
                       autocomplete="new-password" class="{{ $control }}">
                @error('password')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="{{ $label }}">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required
                       autocomplete="new-password" class="{{ $control }}">
            </div>

            <div>
                <label for="role" class="{{ $label }}">Role</label>
                <select id="role" name="role" class="{{ $control }}">
                    @foreach ($roles as $value => $text)
                        <option value="{{ $value }}" @selected(old('role', 'cashier') === $value)>{{ $text }}</option>
                    @endforeach
                </select>
                @error('role')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button type="button" id="closeUserModal" class="{{ $secondary }}">Cancel</button>
                <button type="submit" class="{{ $primary }}">
                    <x-icon name="plus" size="4" />
                    Create user
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Edit --}}
<div id="editUserModal" class="{{ $overlay }}" role="dialog" aria-modal="true" aria-labelledby="editUserModalTitle">
    <div class="{{ $panel }}">
        <h2 id="editUserModalTitle" class="{{ $heading }}">
            Edit <span id="editUserName"></span>
        </h2>

        <form id="editUserForm" method="POST" action="" class="mt-4 space-y-3">
            @csrf
            @method('PUT')

            <div>
                <label for="edit_name" class="{{ $label }}">Name</label>
                <input type="text" id="edit_name" name="name" required class="{{ $control }}">
            </div>

            <div>
                <label for="edit_email" class="{{ $label }}">Email</label>
                <input type="email" id="edit_email" name="email" required autocomplete="off" class="{{ $control }}">
            </div>

            <div>
                <label for="edit_role" class="{{ $label }}">Role</label>
                <select id="edit_role" name="role" class="{{ $control }}">
                    @foreach (['cashier' => 'Cashier', 'manager' => 'Manager', 'admin' => 'Admin'] as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    You cannot remove your own admin role, and the last admin cannot be demoted - there
                    would be nobody left who could undo it.
                </p>
            </div>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button type="button" id="closeEditUserModal" class="{{ $secondary }}">Cancel</button>
                <button type="submit" class="{{ $primary }}">
                    <x-icon name="check-circle" size="4" />
                    Save changes
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Reset password --}}
<div id="resetPasswordModal" class="{{ $overlay }}" role="dialog" aria-modal="true"
     aria-labelledby="resetPasswordModalTitle">
    <div class="{{ $panel }}">
        <h2 id="resetPasswordModalTitle" class="{{ $heading }}">
            New password for <span id="resetUserName"></span>
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            For someone who is locked out. They are signed out everywhere else, and the change is
            recorded in the audit log - the password itself never is.
        </p>

        <form id="resetPasswordForm" method="POST" action="" class="mt-4 space-y-3">
            @csrf

            <div>
                <label for="reset_password" class="{{ $label }}">New password</label>
                <input type="password" id="reset_password" name="password" required
                       autocomplete="new-password" class="{{ $control }}">
            </div>

            <div>
                <label for="reset_password_confirmation" class="{{ $label }}">Confirm new password</label>
                <input type="password" id="reset_password_confirmation" name="password_confirmation" required
                       autocomplete="new-password" class="{{ $control }}">
            </div>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button type="button" id="closeResetPasswordModal" class="{{ $secondary }}">Cancel</button>
                <button type="submit" class="{{ $primary }}">
                    <x-icon name="key" size="4" />
                    Set password
                </button>
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
