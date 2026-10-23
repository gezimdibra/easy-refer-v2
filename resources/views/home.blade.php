<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>{{ config('app.name') }}</h1>
        <p>Referral tools for local businesses.</p>
        <p><a href="{{ route('login') }}">Business portal sign in</a></p>
    </main>
</body>
</html>
