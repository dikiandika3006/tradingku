<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('users')
            ->orderByRaw('slug = ? desc', [Role::SUPER_ADMIN])
            ->orderBy('id')
            ->get();

        return view('role.index', [
            'roles' => $roles,
            'menus' => Menu::grouped(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $slug = Str::slug($data['name'], '_');
        if ($slug === '' || Role::where('slug', $slug)->exists()) {
            return back()->withInput()->with('error', "Role dengan nama \"{$data['name']}\" sudah ada.");
        }

        Role::create($data + ['slug' => $slug]);

        return redirect()->route('role.index')->with('success', "Role {$data['name']} berhasil ditambahkan.");
    }

    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        if ($role->isSuperAdmin()) {
            return redirect()->route('role.index')->with('error', 'Role Super Admin selalu memiliki akses penuh dan tidak bisa diubah.');
        }

        $role->update($this->validated($request, $role));

        return redirect()->route('role.index')->with('success', "Akses role {$role->name} berhasil diperbarui.");
    }

    public function destroy($id)
    {
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->isSuperAdmin()) {
            return redirect()->route('role.index')->with('error', 'Role Super Admin tidak bisa dihapus.');
        }

        if ($role->users_count > 0) {
            return redirect()->route('role.index')->with('error', "Role {$role->name} masih dipakai {$role->users_count} user. Pindahkan user ke role lain terlebih dahulu.");
        }

        $role->delete();

        return redirect()->route('role.index')->with('success', "Role {$role->name} berhasil dihapus.");
    }

    protected function validated(Request $request, ?Role $existing = null): array
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($existing?->id)],
            'permissions'   => 'nullable|array',
            'permissions.*' => ['string', Rule::in(Menu::keys())],
        ]);

        // Mengelola report tanpa bisa melihatnya tidak masuk akal.
        $permissions = $data['permissions'] ?? [];
        if (in_array('investor_report_manage', $permissions, true)) {
            $permissions[] = 'investor_report';
        }

        return [
            'name'        => $data['name'],
            'permissions' => array_values(array_intersect(Menu::keys(), $permissions)),
        ];
    }
}
