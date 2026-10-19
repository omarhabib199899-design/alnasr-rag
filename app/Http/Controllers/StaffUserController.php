<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Creating/editing scoped internal users (name/phone/email/username/
 * password, assigned a Role) — per an explicit ask, deliberately minimal:
 * no self-service invite email, no deactivate/delete flow yet. Named
 * "Staff" to avoid clashing with the Auth\RegisteredUserController
 * (self-registration) — this is the admin-side "create/edit a user for
 * someone else" flow instead.
 */
class StaffUserController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::with('role')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('users.create', [
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'confirmed', 'min:8'],
            'role_id' => ['nullable', 'exists:roles,id'],
        ]);

        User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'] ?? null,
        ]);

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username,'.$user->id],
            'password' => ['nullable', 'confirmed', 'min:8'],
            'role_id' => ['nullable', 'exists:roles,id'],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'role_id' => $validated['role_id'] ?? null,
        ]);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()->route('users.index')->with('status', 'User updated.');
    }
}
