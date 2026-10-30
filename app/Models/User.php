<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Menu;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if user is super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if user is investor.
     */
    public function isInvestor(): bool
    {
        return $this->role === 'investor';
    }

    /**
     * Role dari Master Role (users.role menyimpan slug role).
     */
    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'slug');
    }

    /**
     * Boleh mengakses menu/hak akses dengan kunci $key (lihat App\Support\Menu).
     */
    public function canAccess(string $key): bool
    {
        // Super Admin tidak bergantung pada isi tabel roles, supaya tidak pernah terkunci.
        return $this->isSuperAdmin() || (bool)$this->roleModel?->allows($key);
    }

    public function roleName(): string
    {
        return $this->roleModel?->name ?? $this->role;
    }

    /**
     * Halaman pertama setelah login: menu pertama yang boleh diakses.
     */
    public function homeUrl(): ?string
    {
        foreach (Menu::keys() as $key) {
            if (Menu::route($key) && $this->canAccess($key)) {
                return route(Menu::route($key));
            }
        }

        return null;
    }
}
