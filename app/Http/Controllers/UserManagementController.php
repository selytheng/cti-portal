<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles:id,name')
            ->when(
                $request->input('search'),
                fn ($q, $s) => $q->where(
                    fn ($qq) => $qq->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")
                )
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => ['search' => $request->input('search')],
            'generatedPassword' => $request->session()->get('generatedPassword'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Create', [
            'assignableRoles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $assignable = $this->assignableRoles($request->user())->pluck('name')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'role' => ['required', 'string', Rule::in($assignable)],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed via cast
            'must_change_password' => true,
        ]);
        $user->assignRole($data['role']);

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->event('created')
            ->log("Created user {$user->email} with role {$data['role']}");

        return redirect()
            ->route('users.index')
            ->with('success', "User {$user->email} created. They must change their password at first login.");
    }

    public function edit(Request $request, User $user)
    {
        $this->authorize('update', $user);

        return Inertia::render('Users/Edit', [
            'managedUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
            'assignableRoles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $assignable = $this->assignableRoles($request->user())->pluck('name')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', 'string', Rule::in($assignable)],
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
        ]);

        $roleChanged = $data['role'] !== ($user->roles->first()->name ?? null);

        if ($roleChanged && $request->user()->id === $user->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        $before = ['name' => $user->name, 'email' => $user->email, 'role' => $user->roles->first()->name];

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if ($roleChanged) {
            $user->syncRoles([$data['role']]);
        }

        $passwordReset = false;
        if (! empty($data['password'])) {
            $user->forceFill(['password' => $data['password'], 'must_change_password' => true])->save();
            $passwordReset = true;
        }

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->event('updated')
            ->withProperties(['before' => $before, 'after' => ['name' => $user->name, 'email' => $user->email, 'role' => $data['role']], 'password_reset' => $passwordReset])
            ->log("Updated user {$user->email}");

        return redirect()->route('users.index')->with('success', "User {$user->email} updated.");
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);

        $email = $user->email;
        $user->delete();

        activity()
            ->causedBy($request->user())
            ->event('deleted')
            ->log("Deleted user {$email}");

        return redirect()->route('users.index')->with('success', "User {$email} deleted.");
    }

    /**
     * Superadmin can assign any core role; admin can only manage
     * accounts inside the `monitoring` role (spec Section 2).
     */
    private function assignableRoles(User $actor)
    {
        if ($actor->hasRole('superadmin')) {
            return Role::query()
                ->whereIn('name', ['superadmin', 'admin', 'monitoring'])
                ->orderBy('id')
                ->get(['id', 'name']);
        }

        return Role::query()->where('name', 'monitoring')->get(['id', 'name']);
    }
}
