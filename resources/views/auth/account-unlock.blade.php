@extends('layouts.auth')

@section('title', 'Unlock your account')

@section('content')
    {{-- AU-8 (R5 pack A5): the unlock screen on the shared status
         pattern (AU-5). Copy is the shipped copy, plus the muted
         not-you line (Amendment 26 R5-AUTH-02, PROPOSED). --}}
    <x-auth-status icon="lock" headline="Unlock your account">
        <p class="type-body mt-2 text-text-2">Sign-in to your account was temporarily locked after repeated failed attempts. Pressing the button below clears that lock only; you will still need your password to sign in.</p>

        <x-slot name="actions">
            <form method="POST" action="{{ route('account.unlock') }}" data-loading>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="btn btn-primary h-12 w-full">Unlock my account</button>
            </form>
            <p class="type-caption mt-3 text-text-2">Not you? Do nothing: the account stays locked, and email support@easy-refer.com.</p>
        </x-slot>
    </x-auth-status>
@endsection
