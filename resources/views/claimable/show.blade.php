@extends('layouts.claimable')
{{-- G1 claim page, Variant A order (design pass §2.3): preview
     strip -> pitch -> WHY/HOW/IDEAS -> poster -> demo -> cost ->
     claim -> disclosure/footer. Every string is ClaimableCopy,
     rendered only in its §10.3 valid state (this view is P1/P2). --}}
@section('title', 'A preview made for '.$page->shop_name)
@section('content')
    <p class="preview-strip" data-copy="C01">{{ \App\Support\ClaimableCopy::c01($page) }}</p>

    <h1>{{ $page->shop_name }}</h1>
    <p>{{ $page->snapshotAddressLine() }}</p>
    @if ($page->opening_hours)
        <p>Hours from your listing:
            @foreach ($page->opening_hours as $line) {{ $line }} @endforeach
        </p>
    @endif

    <section>
        <p data-copy="C04">{{ \App\Support\ClaimableCopy::c04($page) }}</p>
        <p data-copy="C05">{{ \App\Support\ClaimableCopy::c05() }}</p>
        <p data-copy="C06">{{ \App\Support\ClaimableCopy::c06($page) }}</p>
        <p data-copy="C07">{{ \App\Support\ClaimableCopy::c07($page) }}</p>
        <p data-copy="C02">{{ \App\Support\ClaimableCopy::c02($page) }}</p>
    </section>

    <section>
        <p data-copy="C08">{{ \App\Support\ClaimableCopy::c08() }}</p>
        @include('claimable._poster', ['page' => $page, 'token' => $token])
    </section>

    <section>
        <p data-copy="C10">{{ \App\Support\ClaimableCopy::c10() }}</p>
        <p><a class="btn" href="{{ route('claimable.demo', ['token' => $token]) }}">Try it as a customer</a></p>
    </section>

    <section>
        @foreach (\App\Support\ClaimableCopy::costBlock() as $id => $line)
            <p data-copy="{{ $id }}">{{ $line }}</p>
        @endforeach
    </section>

    <section>
        <h2 data-copy="C20">{{ \App\Support\ClaimableCopy::c20($page) }}</h2>
        <p><a class="btn" href="{{ route('claimable.claim', ['token' => $token]) }}">Claim this page</a></p>
    </section>

    <footer>
        <p data-copy="C33">{{ \App\Support\ClaimableCopy::c33($page) }}</p>
        <form method="POST" action="{{ route('claimable.remove', ['token' => $token]) }}">
            @csrf
            <button type="submit" class="text-action" data-copy="C34">{{ \App\Support\ClaimableCopy::c34() }}</button>
        </form>
        <p data-copy="C03">{{ \App\Support\ClaimableCopy::c03($page) }}</p>
    </footer>
@endsection
