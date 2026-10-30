@extends('layouts.claimable')
{{-- The §4 claim gate (P3): the code goes to the shop's LISTED
     phone only. The claimer never types a destination number —
     that is what makes a wrong phone unable to complete a claim. --}}
@section('title', 'Claim '.$page->shop_name)
@section('content')
    <p class="preview-strip" data-copy="C01">{{ \App\Support\ClaimableCopy::c01($page) }}</p>

    @if ($hasPhone)
        <p data-copy="C22">{{ \App\Support\ClaimableCopy::c22($page) }}</p>
        <form method="POST" action="{{ route('claimable.gate.send', ['token' => $token]) }}">
            @csrf
            <button type="submit" name="channel" value="sms" class="btn">{{ \App\Support\ClaimableCopy::c23()['sms'] }}</button>
            <button type="submit" name="channel" value="call" class="btn">{{ \App\Support\ClaimableCopy::c23()['call'] }}</button>
        </form>
        <p data-copy="C24">{{ \App\Support\ClaimableCopy::c24() }}</p>

        @if ($codeSent)
            <form method="POST" action="{{ route('claimable.gate.check', ['token' => $token]) }}">
                @csrf
                <label for="code">6-digit code</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                <button type="submit" class="btn">Check the code</button>
            </form>
        @endif
    @endif

    @if ($error ?? null)
        <p class="error" role="alert">{{ $error }}</p>
    @endif

    <section>
        <p data-copy="C30">{{ \App\Support\ClaimableCopy::c30() }}</p>
        <form method="POST" action="{{ route('claimable.review', ['token' => $token]) }}">
            @csrf
            <label for="rv-name">Your name</label>
            <input id="rv-name" name="name" required>
            <label for="rv-phone">A number where we can reach you</label>
            <input id="rv-phone" name="phone" type="tel" required>
            <button type="submit" class="btn">Ask us to check this claim</button>
        </form>
    </section>

    <p data-copy="C21">{{ \App\Support\ClaimableCopy::c21() }}</p>

    <footer>
        <form method="POST" action="{{ route('claimable.remove', ['token' => $token]) }}">
            @csrf
            <button type="submit" class="text-action" data-copy="C34">{{ \App\Support\ClaimableCopy::c34() }}</button>
        </form>
        <p data-copy="C03">{{ \App\Support\ClaimableCopy::c03($page) }}</p>
    </footer>
@endsection
