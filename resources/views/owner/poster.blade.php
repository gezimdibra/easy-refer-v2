<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Poster | {{ $business->name }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .poster-actions { display: none; }
            body { background: #ffffff; }
        }
    </style>
</head>
<body class="bg-white text-text-1">
    {{-- A designed printable poster (DASH-08): the business name,
         the live code, and the join line, sized per placement.
         Screen: a display or tablet. Counter: A5. Window: a full
         page meant to be read from outside the glass. --}}
    @php
        $sizes = match ($variant) {
            'screen' => ['wrap' => 'max-w-md', 'name' => 'text-3xl', 'qr' => 'w-80 h-80'],
            'counter' => ['wrap' => 'max-w-lg', 'name' => 'text-4xl', 'qr' => 'w-96 h-96'],
            default => ['wrap' => 'max-w-2xl', 'name' => 'text-5xl', 'qr' => 'w-[560px] h-[560px] max-w-full'],
        };
    @endphp
    <main class="mx-auto {{ $sizes['wrap'] }} px-6 py-10 text-center">
        <p class="{{ $sizes['name'] }} font-bold">{{ $business->name }}</p>
        <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="{{ $sizes['qr'] }} mx-auto mt-6">
        <p class="mt-6 text-xl">Customers scan this code to join and start referring.</p>
        <p class="poster-actions mt-8">
            <a class="btn btn-secondary" href="{{ route('owner.qr') }}">Back to your QR code</a>
        </p>
    </main>
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
