<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Tradingku</title>
    <!-- PWA Configuration -->
    <meta name="theme-color" content="#0b0e13">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <!-- CSS File -->
    @include('partials.tema')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body class="login-wrapper">
    <div class="glass-card login-card">
        <div class="login-header">
            <div class="brand brand-lg">@include('partials.logo', ['size' => 48])<span class="brand-name">Trading<span>ku</span></span></div>
            <p>Silakan login untuk memantau trading akun Tokocrypto Anda</p>
        </div>

        @if($errors->any())
            <div class="alert alert-danger">
                {{ $errors->first() }}
            </div>
        @endif

        <form action="{{ url('/login') }}" method="POST">
            @csrf
            
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Kata Sandi</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="form-group flex-between" style="margin-top:1.5rem; margin-bottom:1.5rem;">
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; color:var(--text-muted); cursor:pointer;">
                    <input type="checkbox" name="remember" style="accent-color:var(--color-primary);"> Ingat Saya
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:0.9rem;">
                Masuk ke Dashboard
            </button>
        </form>
    </div>

    <!-- PWA Service Worker Clean-up (Self-heals browsers stuck with crashed service workers) -->
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(registrations => {
                let didUnregister = false;
                for (let registration of registrations) {
                    registration.unregister().then(success => {
                        if (success && !didUnregister) {
                            didUnregister = true;
                            console.log('Crashed Service Worker unregistered successfully. Reloading page...');
                            window.location.reload();
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>
