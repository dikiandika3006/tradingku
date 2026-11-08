@extends('layouts.app')

@section('title', 'Master Role')

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>Master Role</h1>
        <p>Atur menu dan laporan apa saja yang boleh dilihat tiap role, lalu pilih role untuk tiap user di Daftar User</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger mb-4">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger mb-4">{{ $errors->first() }}</div>
@endif

<div class="mb-6">
    <div class="glass-card">
        <div class="card-title"><span>Daftar Role</span></div>
        <div class="table-responsive">
            <table class="custom-table table-kartu">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Menu yang Boleh Diakses</th>
                        <th style="text-align: right;">User</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($roles as $role)
                        <tr>
                            <td class="kartu-judul">
                                <div style="font-weight: 700;">{{ $role->name }}</div>
                                <div style="font-size: 0.72rem; color: var(--text-muted); font-family: monospace;">{{ $role->slug }}</div>
                            </td>
                            <td data-label="Menu">
                                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                                    @forelse($role->permissionLabels() as $label)
                                        <span class="badge {{ $role->isSuperAdmin() ? 'badge-success' : 'badge-neutral' }}">{{ $label }}</span>
                                    @empty
                                        <span style="color: var(--text-muted); font-size: 0.85rem;">Belum ada menu</span>
                                    @endforelse
                                </div>
                            </td>
                            <td data-label="User" style="text-align: right; font-family: monospace;">{{ $role->users_count }}</td>
                            <td data-label="Aksi" style="text-align: right;">
                                @if($role->isSuperAdmin())
                                    <span style="color: var(--text-muted); font-size: 0.8rem;">Akses penuh, terkunci</span>
                                @else
                                    <div style="display: inline-flex; gap: 0.75rem; align-items: center;">
                                        <button type="button" class="btn-edit-role" title="Edit role"
                                                data-id="{{ $role->id }}"
                                                data-name="{{ $role->name }}"
                                                data-permissions='@json($role->permissions ?? [])'
                                                style="background: none; border: none; color: var(--color-secondary); cursor: pointer; display: flex;">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        @if($role->users_count === 0)
                                            <form action="{{ route('role.destroy', $role->id) }}" method="POST" onsubmit="return confirm('Hapus role {{ $role->name }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" title="Hapus role" style="background: none; border: none; color: var(--tc-ef4444); cursor: pointer; display: flex;">
                                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="glass-card" id="roleFormCard">
    <div class="card-title"><span id="roleFormTitle">Tambah Role</span></div>
    <form action="{{ route('role.store') }}" method="POST" id="roleForm">
        @csrf
        <div id="roleMethod"></div>

        <div class="form-group">
            <label for="role_name" class="form-label">Nama Role</label>
            <input type="text" name="name" id="role_name" class="form-control" maxlength="100" placeholder="contoh: Investor Pelagic" value="{{ old('name') }}" required>
        </div>

        <label class="form-label">Menu yang Boleh Diakses</label>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            @foreach($menus as $group => $items)
                <div style="border: 1px solid rgba(var(--tint-rgb), 0.08); border-radius: 10px; padding: 0.85rem 1rem;">
                    <div style="font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">{{ $group }}</div>
                    @foreach($items as $key => $label)
                        <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0; cursor: pointer; font-size: 0.9rem;">
                            <input type="checkbox" name="permissions[]" value="{{ $key }}" class="perm-check" {{ in_array($key, old('permissions', [])) ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            @endforeach
        </div>
        <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0;">
            Menu di grup Administrasi (Daftar User, Master Role, API Settings) memberi kendali besar atas aplikasi; berikan hanya ke role yang dipercaya.
        </p>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" id="roleSubmit" class="btn btn-primary">Tambah Role</button>
            <button type="button" id="roleCancel" class="btn btn-secondary" style="display: none;">Batal</button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('roleForm');
    const title = document.getElementById('roleFormTitle');
    const method = document.getElementById('roleMethod');
    const submit = document.getElementById('roleSubmit');
    const cancel = document.getElementById('roleCancel');
    const nameInput = document.getElementById('role_name');
    const checks = document.querySelectorAll('.perm-check');

    document.querySelectorAll('.btn-edit-role').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const perms = JSON.parse(btn.dataset.permissions || '[]');
            title.textContent = 'Edit Role: ' + btn.dataset.name;
            form.action = '{{ url('role') }}/' + btn.dataset.id;
            method.innerHTML = '<input type="hidden" name="_method" value="PUT">';
            submit.textContent = 'Simpan Perubahan';
            cancel.style.display = 'inline-block';
            nameInput.value = btn.dataset.name;
            checks.forEach(function (c) { c.checked = perms.includes(c.value); });
            document.getElementById('roleFormCard').scrollIntoView({ behavior: 'smooth' });
        });
    });

    cancel.addEventListener('click', function () {
        title.textContent = 'Tambah Role';
        form.action = '{{ route('role.store') }}';
        method.innerHTML = '';
        submit.textContent = 'Tambah Role';
        cancel.style.display = 'none';
        nameInput.value = '';
        checks.forEach(function (c) { c.checked = false; });
    });
})();
</script>
@endsection
