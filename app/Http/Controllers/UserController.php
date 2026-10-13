<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Admin user management. Moved off SettingController so the settings screen is
 * about settings and this is about people.
 *
 * The guard rails in here are the point of the class, so they are stated once:
 *
 *  - You cannot take your own admin role away, and you cannot deactivate or
 *    delete yourself. Doing so logs you out of the only screen that could undo
 *    it, and in a one-admin shop nobody is left who can help.
 *  - The last admin cannot be demoted, deactivated or deleted, by anyone. Same
 *    reason, one step removed.
 *  - Deactivating is the normal way to retire an account. Deleting is the last
 *    resort, because a user's name hangs off every sale they ever rang up.
 *
 * Each refusal is a flash message, not a 403: the admin did something
 * reasonable-looking and deserves to be told why it is not allowed. Who may
 * reach these actions at all is UserPolicy's job.
 */
class UserController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $user = new User();
        $user->fill(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->role = $data['role']; // not mass-assignable; the model syncs the Spatie role on save
        $user->save();

        AuditLog::record('Created User', 'users', $user->id);

        return redirect()->route('settings.index')->with('success', 'User created successfully.');
    }

    /** Edit a user's name, email and role. */
    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            // Unique except for this user, or saving a form you did not change
            // would report their own address as taken.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $losingAdmin = $user->role === 'admin' && $data['role'] !== 'admin';

        if ($losingAdmin && $user->id === $request->user()->id) {
            return back()->with('error', 'You cannot remove your own admin role. Ask another admin to do it.');
        }

        if ($losingAdmin && $user->isLastAdmin()) {
            return back()->with('error', 'This is the last admin. Make someone else an admin first.');
        }

        DB::transaction(function () use ($user, $data) {
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            $user->role = $data['role']; // the model re-syncs the Spatie role on save
            $user->save();

            AuditLog::record('Updated User', 'users', $user->id);
        });

        return back()->with('success', 'User updated successfully.');
    }

    /**
     * An admin sets a new password for someone who is locked out.
     *
     * The old password is not asked for - the whole point is that the user
     * cannot supply it. Password::defaults() is used so an admin-set password is
     * held to the same strength rules as one the user chooses.
     */
    public function resetPassword(Request $request, User $user)
    {
        Gate::authorize('resetPassword', $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->password = $data['password']; // hashed by the model cast
            $user->save();

            $this->signOutEverywhereElse($user);

            // Never the password, never a hash: just that it happened, to whom.
            AuditLog::record('Reset User Password', 'users', $user->id);
        });

        return back()->with('success', $user->name . "'s password has been changed.");
    }

    /**
     * Deactivate or reactivate an account.
     *
     * This is the replacement for deleting someone: their sales, shifts and
     * audit trail stay exactly where they are, and only the login stops
     * working (see App\Http\Middleware\EnsureUserIsActive).
     */
    public function toggleStatus(Request $request, User $user)
    {
        Gate::authorize('toggleStatus', $user);

        $deactivating = $user->isActive();

        if ($deactivating && $user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account - you would be logged out of it.');
        }

        // Counted among *active* admins: an inactive admin cannot sign in to
        // reactivate anyone, so they do not count as cover.
        if ($deactivating && $user->isLastAdmin(activeOnly: true)) {
            return back()->with('error', 'This is the last active admin. Activate another admin first.');
        }

        DB::transaction(function () use ($user, $deactivating) {
            $user->is_active = !$deactivating;
            $user->save();

            if ($deactivating) {
                // Belt and braces with the middleware: this drops the session
                // row and the remember-me cookie as well, so there is nothing
                // left to replay.
                $this->signOutEverywhereElse($user);
            }

            AuditLog::record($deactivating ? 'Deactivated User' : 'Reactivated User', 'users', $user->id);
        });

        return back()->with(
            'success',
            $deactivating
                ? $user->name . ' can no longer sign in. Their history is unchanged.'
                : $user->name . ' can sign in again.'
        );
    }

    public function destroy(User $user)
    {
        Gate::authorize('delete', $user);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account here.');
        }

        if ($user->isLastAdmin()) {
            return back()->with('error', 'You cannot delete the last admin.');
        }

        DB::transaction(function () use ($user) {
            AuditLog::record('Deleted User', 'users', $user->id);
            $this->signOutEverywhereElse($user);
            $user->delete();
        });

        return back()->with('success', 'User deleted successfully.');
    }

    /**
     * End this user's other sessions.
     *
     * Two separate things:
     *
     *  - A new remember_token invalidates any "remember me" cookie, whatever
     *    the session driver is.
     *  - With the database session driver, their session rows can be deleted
     *    outright. The file and cookie drivers hold no index of whose session
     *    is whose, so there is nothing to delete there - the EnsureUserIsActive
     *    middleware is what catches those on the next request.
     */
    private function signOutEverywhereElse(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($user->id === auth()->id(), fn ($q) => $q->where('id', '!=', session()->getId()))
                ->delete();
        }
    }
}
