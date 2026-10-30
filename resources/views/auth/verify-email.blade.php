@extends('layouts.auth')

@section('title', 'Verify your email')

@section('content')
    {{-- AU-4 (R5 pack A3): the verify gate on the shared status
         pattern. The address the link went to is shown in a mono
         well, the consequence stands as its own sentence, resend
         is the primary action (it is the only action on the
         screen), and the just-resent state is designed: a
         confirmation well plus the resend disabled with its wait
         note. New strings are Amendment 26 R5-AUTH-01 (PROPOSED). --}}
    <x-auth-status icon="mail" headline="Check your email">
        <p class="type-body mt-2 text-text-2">We sent a verification link to your email address. Open it to verify your account.</p>

        <p class="well mt-4 break-all font-mono text-sm text-text-1">{{ auth()->user()->email }}</p>

        <p class="type-body mt-4 text-text-2">Your email is not verified yet. Verify it so your receipts and password resets reach you.</p>

        @if (session('status') === 'verification-link-sent')
            <p class="status status-success mt-4" role="status">A new verification link is on its way to your email address.</p>
        @endif

        <x-slot name="actions">
            <form method="POST" action="{{ route('verification.send') }}" data-loading>
                @csrf
                @if (session('status') === 'verification-link-sent')
                    <button type="submit" class="btn btn-primary h-12 w-full" disabled>Resend the verification email</button>
                    <p class="type-caption mt-2 text-center text-text-2">You can send another in about a minute.</p>
                @else
                    <button type="submit" class="btn btn-primary h-12 w-full">Resend the verification email</button>
                @endif
            </form>
            <p class="type-caption mt-3 text-text-2">The link works for 60 minutes. Check spam if it does not arrive.</p>
        </x-slot>

        <x-slot name="footer">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="type-body-sm text-text-2">Wrong address?</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-action">Sign out</button>
                </form>
            </div>
        </x-slot>
    </x-auth-status>
@endsection
