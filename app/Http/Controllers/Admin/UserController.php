<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Account management. The only route into the system for a new user, since
 * there is no self-registration (FR-105, FR-401, FR-402).
 *
 * Deactivation rather than deletion throughout: a person's inspections and
 * tickets must survive them leaving (NFR-06).
 *
 * @see docs/modules/M1-identity-and-audit.md
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->when($request->string('search')->trim()->value(), fn ($q, $term) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('tp_number', 'like', "%{$term}%")
            ))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status') === 'active'))
            ->orderBy($this->sortColumn($request), $request->string('direction') === 'desc' ? 'desc' : 'asc')
            ->paginate(20)
            // Without this, paging away from page 1 drops the filters the user
            // just chose and the list appears to reset itself.
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::options(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', ['roles' => UserRole::options()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->except(['role', 'password']));
        $user->password = Hash::make($request->validated('password'));

        // Privilege is never mass assigned — see the $fillable docblock on the
        // model. A new account must change the password an admin chose for it.
        $user->forceFill([
            'role' => $request->validated('role'),
            'is_active' => true,
            'must_change_password' => true,
        ])->save();

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', "Account created for {$user->name}.");
    }

    public function show(User $user): View
    {
        $this->authorize('view', $user);

        return view('admin.users.show', [
            'user' => $user,
            'events' => AuditLog::forSubject($user)->with('user')->latest('created_at')->limit(30)->get(),
        ]);
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => UserRole::options(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->except(['role', 'password']));

        if ($request->filled('password')) {
            $user->password = Hash::make($request->validated('password'));
            $user->must_change_password = true;
        }

        // An administrator cannot change their own role — the only way to
        // leave the system with no administrator at all.
        if ($request->user()->can('changeRole', $user)) {
            $user->role = $request->validated('role');
        }

        $user->save();

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Account updated.');
    }

    /**
     * Deactivate or reactivate. Never delete (FR-106).
     */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        return back()->with(
            'success',
            $user->is_active
                ? "{$user->name} can sign in again."
                : "{$user->name} has been deactivated. Their history is kept."
        );
    }

    private function sortColumn(Request $request): string
    {
        // Whitelisted: an unchecked sort parameter is an ORDER BY injection.
        return in_array($request->string('sort')->value(), ['name', 'email', 'role', 'created_at'], true)
            ? $request->string('sort')->value()
            : 'name';
    }
}
