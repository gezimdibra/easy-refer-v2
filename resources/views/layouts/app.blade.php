<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; color: #1a202c; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; }
        header nav { display: flex; gap: 1rem; align-items: center; }
        main { max-width: 44rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        fieldset { margin: 0 0 1.5rem; padding: 1rem; border: 1px solid #cbd5e0; border-radius: 0.5rem; }
        legend { font-weight: 600; padding: 0 0.5rem; }
        label { display: block; font-weight: 600; margin: 0.75rem 0 0.25rem; }
        input { width: 100%; padding: 0.5rem; border: 1px solid #a0aec0; border-radius: 0.375rem; box-sizing: border-box; }
        button { padding: 0.55rem 1.1rem; border: 0; border-radius: 0.375rem; background: #2b6cb0; color: #fff; cursor: pointer; }
        .errors { background: #fff5f5; border: 1px solid #feb2b2; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
        .status { background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
        .gate { background: #fffff0; border: 1px solid #ecc94b; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.5rem; border-bottom: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <header>
        <a href="{{ route('home') }}"><strong>{{ config('app.name') }}</strong></a>
        @auth
            <nav>
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('security') }}">Security</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Sign out</button>
                </form>
            </nav>
        @endauth
    </header>
    <main>
        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
