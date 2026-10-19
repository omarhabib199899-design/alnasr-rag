<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;

/**
 * Role management — per an explicit ask (voice note): create a Role, pick
 * which nav sections / customer tabs it can see, nothing more granular yet
 * (no per-action permissions, no field-level rules). See PermissionCatalog
 * for the full list of grantable keys.
 */
class RoleController extends Controller
{
    public function index()
    {
        return view('roles.index', [
            'roles' => Role::withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('roles.create', [
            'groupedPermissions' => PermissionCatalog::grouped(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        Role::create([
            'name' => $validated['name'],
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return redirect()->route('roles.index')->with('status', 'Role created.');
    }
}
