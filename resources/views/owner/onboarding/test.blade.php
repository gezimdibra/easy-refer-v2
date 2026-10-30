@extends('layouts.onboarding')

@section('title', 'Try it yourself')

@section('content')
    @include('owner.onboarding._progress')

    {{-- OB-5 Test (design pack Section 4.6). OB-U1 carries the
         step's states; OB-U5 adds the poll-on-focus detection
         refresh. The step completes on the checklist's
         test_referral predicate (a live membership on the
         owner's own number, SELF-01 semantics). --}}
    <h1 class="type-title-2 text-text-1">See it work, as your customer.</h1>
    <p class="type-body mt-2 text-text-2">Scan your own code with your phone's camera and join with your own number, the same two taps your customers will make. When your join lands, this step checks itself off and you will know it works.</p>

    {{-- OB-5 inactive branch (Amendment 38, Train A3-4, F-17):
         the poster step's pattern. While the flow is off the
         join cannot land, so the notice names the missing step
         and the waiting line below does not render. --}}
    @unless ($flowActive)
        <div class="mt-6">
            @include('partials.qr-inactive-notice')
        </div>
    @endunless

    <div class="panel mt-6 text-center">
        <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" width="280" height="280" class="mx-auto">
        <p class="type-body mt-4 text-text-2">Can't scan? Go to <a class="text-action" href="{{ route('join.show', ['slug' => $business->ensureJoinSlug()]) }}">{{ $joinDisplay }}</a></p>
    </div>

    @if ($done)
        <p class="mt-6">
            <a class="btn btn-primary" href="{{ route('onboarding.finish') }}">It worked, continue</a>
        </p>
    @elseif ($flowActive)
        <p class="type-body mt-6 text-text-2">Waiting for your join… (checks automatically)</p>
        <script>
            // Detection polish (OB-U5): the predicate is read
            // server-side; returning to this tab re-reads it.
            window.addEventListener('focus', function () { window.location.reload(); });
        </script>
        <p class="mt-4">
            <a class="text-action" href="{{ route('onboarding.finish') }}">Skip the test</a>
        </p>
    @else
        <p class="mt-4">
            <a class="text-action" href="{{ route('onboarding.finish') }}">Skip the test</a>
        </p>
    @endif
@endsection
