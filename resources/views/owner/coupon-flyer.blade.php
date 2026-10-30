<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Coupon flyer | {{ $business->name }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4 portrait; margin: 0; }
        @media print {
            .poster-actions { display: none; }
            body { background: #ffffff; }
            .poster-sheet { border: 0 !important; box-shadow: none !important; margin-top: 0 !important; margin-bottom: 0 !important; }
        }
    </style>
</head>
<body class="bg-surface-1 text-text-1 theme-light-lock">
    {{-- The coupon flyer (ID-11, Amendment 53, COUP-16..20):
         a poster-family artefact for a passerby anywhere
         EXCEPT the store, who has never heard of Easy Refer.
         The offer prints verbatim from the batch snapshot; the
         terms line always prints; the typed fallback runs full
         width and is never truncated (the r47 card-fit rule).
         Owner guidance (COUP-11) never prints here. --}}
    <div class="poster-actions mx-auto my-4 flex max-w-xl items-center justify-between px-4">
        <a class="btn btn-secondary" href="{{ route('owner.qr.coupons') }}">Back to Coupons</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
    </div>

    <main class="poster-sheet mx-auto my-6 max-w-xl border border-line bg-white p-8 text-center" style="width: 190mm; min-height: 250mm">
        <p class="type-title-2">{{ $business->name }}, {{ $business->street }}.</p>

        <h1 class="mt-8" style="font-size: 44px; line-height: 48px; font-weight: 700">{{ $batch->offer_text }}</h1>

        <p class="type-body mt-6">Scan, verify your phone, show it at the counter.</p>

        <div class="mt-8 flex justify-center">
            <img src="{{ $imageUrl }}" alt="Coupon QR code" width="280" height="280">
        </div>

        <p class="type-body mt-6" style="overflow-wrap: anywhere">{{ $shortLink }}</p>

        <p class="type-body mt-8">One per person. Good until {{ $business->localTime($batch->expires_at)->format('F j, Y') }}. At {{ $business->name }} only.</p>

        <p class="type-caption mt-10 text-text-2">Powered by Easy Refer</p>
    </main>

    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
