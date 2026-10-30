<?php

namespace App\Http\Middleware;

use App\Support\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMenuAccess
{
    /**
     * Izinkan bila role user boleh mengakses menu $key (diatur di Master Role).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (!$request->user() || !$request->user()->canAccess($key)) {
            abort(403, 'Akses ditolak. Role Anda tidak memiliki akses ke menu: ' . Menu::label($key));
        }

        return $next($request);
    }
}
