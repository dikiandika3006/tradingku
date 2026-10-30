<?php

namespace App\Models;

use App\Support\Menu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    protected $fillable = [
        'slug',
        'name',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'slug');
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN;
    }

    public function allows(string $key): bool
    {
        return $this->isSuperAdmin() || in_array($key, $this->permissions ?? [], true);
    }

    /** Label menu yang diizinkan, untuk ditampilkan di daftar role. */
    public function permissionLabels(): array
    {
        if ($this->isSuperAdmin()) {
            return ['Semua menu'];
        }

        return array_map(fn ($k) => Menu::label($k), array_values(array_intersect(Menu::keys(), $this->permissions ?? [])));
    }
}
