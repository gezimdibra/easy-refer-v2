@extends('layouts.onboarding')

@section('title', 'Verify your email')

@section('content')
    @include('owner.onboarding._progress')

    {{-- OB-1 Verify (design pack Section 4.2), after Card per
         benchmark ruling O-1 (Step 4 since RS2-2 added the
         Location step). The step completes when the
         checklist's email predicate flips; the screen re-checks
         on focus and the verification link returns here while
         onboarding is open (OB-U2). --}}
    <h1 class="type-title-2 text-text-1">Check your inbox.</h1>

    @if ($done)
        <div class="panel mt-6">
            <p class="font-semibold text-text-1">✓ Email verified</p>
            <p class="mt-4"><a class="btn btn-primary" href="{{ route('onboarding.step', $next) }}">Continue</a></p>
        </div>
    @else
        <p class="type-body mt-2 text-text-2">We sent a verification link to <strong class="text-text-1">{{ $user->email }}</strong>. Open it on this phone and this step checks itself off. It also makes sure your receipts and password resets reach you.</p>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-6" data-loading>
            @csrf
            <button type="submit" class="btn btn-primary">Resend the email</button>
        </form>

        <p class="mt-6">
            <a class="text-action" href="{{ route('onboarding.step', $next) }}">Skip for now</a>
        </p>
        <p class="type-body-sm mt-2 text-text-2">Wrong address? Change it in <a class="font-semibold text-link hover:underline" href="{{ route('settings.account') }}">Settings → Account</a>.</p>

        <script>
            // Poll on focus (pack 4.2): returning to this tab
            // after opening the link elsewhere re-reads the
            // predicate from the server; no state is faked here.
            window.addEventListener('focus', function () { window.location.reload(); });
        </script>
    @endif
@endsection
