<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  Salah satu role yang diizinkan, mis. role:super_admin,report_viewer
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || !in_array($request->user()->role, $roles, true)) {
            abort(403, 'Akses ditolak. Halaman ini hanya untuk pengguna dengan hak akses: ' . implode(', ', $roles));
        }

        return $next($request);
    }
}
