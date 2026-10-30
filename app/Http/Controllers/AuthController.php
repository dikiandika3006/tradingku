<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLogin()
    {
        if (Auth::check() && Auth::user()->homeUrl()) {
            return redirect(Auth::user()->homeUrl());
        }
        return view('auth.login');
    }

    /**
     * Handle authentication attempt.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $remember = true;

        if (Auth::attempt($credentials, $remember)) {
            $home = Auth::user()->homeUrl();

            // Role tanpa satu pun menu tidak punya halaman untuk dituju.
            if (!$home) {
                Auth::logout();

                return back()->withErrors([
                    'email' => 'Role akun Anda belum diberi akses ke menu apa pun. Hubungi admin.',
                ])->onlyInput('email');
            }

            $request->session()->regenerate();
            return redirect()->intended($home);
        }

        return back()->withErrors([
            'email' => 'Kredensial yang diberikan tidak cocok dengan catatan kami.',
        ])->onlyInput('email');
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
