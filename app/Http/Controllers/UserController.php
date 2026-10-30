<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index()
    {
        $users = User::with('roleModel')->orderBy('name', 'asc')->get();
        // Super Admin di urutan terakhir supaya tidak jadi pilihan default.
        $roles = Role::orderByRaw('slug = ?', [Role::SUPER_ADMIN])->orderBy('id')->get();
        return view('user.index', compact('users', 'roles'));
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|string|exists:roles,slug',
        ]);

        if ($denied = $this->superAdminGuard($request->role)) {
            return $denied;
        }

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return redirect()->route('user.index')->with('success', 'User berhasil ditambahkan.');
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6|confirmed',
            'role' => 'required|string|exists:roles,slug',
        ]);

        if ($denied = $this->superAdminGuard($request->role, $user)) {
            return $denied;
        }

        // Prevent self-demotion
        if (Auth::id() === $user->id && $request->role !== 'super_admin') {
            return redirect()->route('user.index')->with('error', 'Anda tidak dapat mengubah/menurunkan role Anda sendiri.');
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->role = $request->role;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return redirect()->route('user.index')->with('success', 'User berhasil diperbarui.');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Prevent deleting oneself
        if (Auth::id() === $user->id) {
            return redirect()->route('user.index')->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        if ($denied = $this->superAdminGuard(null, $user)) {
            return $denied;
        }

        $user->delete();

        return redirect()->route('user.index')->with('success', 'User berhasil dihapus.');
    }

    /**
     * Hanya Super Admin yang boleh memberi role Super Admin atau mengubah akun
     * Super Admin; user lain yang diberi menu Daftar User tidak bisa menaikkan
     * hak aksesnya sendiri lewat halaman ini.
     */
    protected function superAdminGuard(?string $newRole, ?User $target = null)
    {
        $touchesSuperAdmin = $newRole === Role::SUPER_ADMIN || $target?->isSuperAdmin();

        if ($touchesSuperAdmin && !Auth::user()->isSuperAdmin()) {
            return redirect()->route('user.index')->with('error', 'Hanya Super Admin yang boleh mengelola akun atau role Super Admin.');
        }

        return null;
    }
}
