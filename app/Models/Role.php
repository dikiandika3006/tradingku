<?php

namespace App\Models;

use App\Support\Menu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    /**
     * Role bawaan, sama dengan isi awal migrasi. Dipakai selama tabel roles
     * belum ada (kode baru ter-deploy tapi migrasi belum dijalankan), supaya
     * aplikasi — termasuk tombol migrasi di API Settings — tetap bisa dibuka.
     */
    public const DEFAULTS = [
        'super_admin'   => ['Super Admin', []],
        'investor'      => ['Investor', ['dashboard', 'portfolio']],
        'report_viewer' => ['Viewer Laporan', ['investor_report', 'monthly_report']],
    ];

    protected static ?bool $tableExists = null;

    protected static array $bySlug = [];

    protected $fillable = [
        'slug',
        'name',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    protected static function booted(): void
    {
        // Cache per proses harus ikut berubah saat role diubah.
        static::saved(fn () => static::$bySlug = []);
        static::deleted(fn () => static::$bySlug = []);
    }

    /** Role untuk sebuah slug, dari tabel roles atau dari DEFAULTS bila tabelnya belum ada. */
    public static function forSlug(?string $slug): ?self
    {
        if ($slug === null) {
            return null;
        }

        if (!array_key_exists($slug, static::$bySlug)) {
            static::$bySlug[$slug] = static::tableExists()
                ? static::where('slug', $slug)->first()
                : static::fromDefaults($slug);
        }

        return static::$bySlug[$slug];
    }

    /** Semua role, dengan fallback yang sama seperti forSlug(). */
    public static function allSafe()
    {
        if (static::tableExists()) {
            return static::orderByRaw('slug = ?', [self::SUPER_ADMIN])->orderBy('id')->get();
        }

        return collect(array_keys(self::DEFAULTS))
            ->sortBy(fn ($slug) => $slug === self::SUPER_ADMIN)
            ->map(fn ($slug) => static::fromDefaults($slug))
            ->values();
    }

    public static function tableExists(): bool
    {
        return static::$tableExists ??= Schema::hasTable('roles');
    }

    protected static function fromDefaults(string $slug): ?self
    {
        if (!isset(self::DEFAULTS[$slug])) {
            return null;
        }

        [$name, $permissions] = self::DEFAULTS[$slug];

        return new static(['slug' => $slug, 'name' => $name, 'permissions' => $permissions]);
    }

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
