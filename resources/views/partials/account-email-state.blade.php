{{--
    SV-FIX-01: the account email and its verification state, one
    rendering for every identity surface (Settings -> Account
    and Security), so the pill, the consequence, and the resend
    action can never drift between pages. Modes: "full" (address
    + pill + actions), "pill" (inline beside an existing address
    sentence), "actions" (flash, consequence, resend, change).
--}}
@php
    $emailVerified = $user->hasVerifiedEmail();
    $emailPart = $part ?? 'full';
    $emailShowAddress = $showAddress ?? true;
    // F-09: in the Security context the partial renders inside
    // the change-email panel itself, so its Change email action
    // would point at the page the owner is already on (and sit
    // directly above the form it names). Security includes pass
    // context=security and the action is suppressed there only;
    // the Account rendering is unchanged.
    $emailContext = $context ?? 'account';
@endphp

@if (in_array($emailPart, ['full', 'pill'], true))
    <span class="inline-flex flex-wrap items-center gap-2 align-middle">
        @if ($emailPart === 'full' && $emailShowAddress)
            <span class="font-medium text-text-1">{{ $user->email }}</span>
        @endif
        @if ($emailVerified)
            <span class="pill pill-success">Verified</span>
        @else
            <span class="pill pill-warning">Not verified</span>
        @endif
    </span>
@endif

@if (in_array($emailPart, ['full', 'actions'], true))
    @if (session('status') === 'verification-link-sent')
        <p class="status mt-3">A new verification link is on its way to your email address.</p>
    @endif

    @if (! $emailVerified)
        <p class="mt-3 text-text-2">Your QR code and billing stay off until this email is verified.</p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('verification.send') }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-secondary">Resend verification email</button>
            </form>
            @if ($emailContext !== 'security')
                <a class="font-semibold text-link hover:underline" href="{{ route('security') }}">Change email</a>
            @endif
        </div>
    @elseif ($emailContext !== 'security')
        <p class="mt-4"><a class="font-semibold text-link hover:underline" href="{{ route('security') }}">Change email</a></p>
    @endif
@endif
