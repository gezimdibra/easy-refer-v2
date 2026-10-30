@extends('layouts.auth')

@section('title', 'Set a new password')

@section('content')
    {{-- AU-6/AU-7 (R5 pack A4 pick B): identity + parity. The email
         is presented as an identity line, not re-asked: the field
         still submits exactly as before (readonly input, same
         name and value), with a "Not you?" escape to the request
         form. The rules checklist is the shared component
         register renders. The expired/used-token error branches
         to the designed status state (R5-AUTH-03, PROPOSED)
         instead of a form error. --}}
    @if ($errors->first('email') === trans('passwords.token'))
        <x-auth-status icon="lock" headline="This link has expired">
            <p class="type-body mt-2 text-text-2">Reset links work for 60 minutes, and only once. Email yourself a fresh one and use it right away.</p>

            <x-slot name="actions">
                <p><a class="btn btn-primary h-12 w-full" href="{{ route('password.request') }}">Email me a new link</a></p>
                <p class="mt-4 text-center"><a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('login') }}">Back to sign in</a></p>
            </x-slot>
        </x-auth-status>
    @else
        <h1 class="type-display-xs text-text-1">Set a new password</h1>

        <form method="POST" action="{{ route('password.store') }}" class="mt-8" data-loading x-data="{ pw: '' }">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="flex items-center gap-3 rounded-xl border border-line bg-surface-0 p-3">
                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-brand-50 text-text-1" aria-hidden="true"><span style="font-size: 14px; font-weight: 650">{{ strtoupper(substr((string) ($accountName ?? $request->email), 0, 1)) }}</span></span>
                <span class="min-w-0">
                    @if ($accountName)
                        <span class="type-title-4 block truncate text-text-1">{{ $accountName }}</span>
                    @endif
                    {{-- The email field itself, presented as the
                         identity line's address: readonly, same
                         name and value as the old editable field. --}}
                    <input class="type-caption block w-full truncate border-0 bg-transparent p-0 text-text-2" id="email" name="email" type="email" value="{{ old('email', $request->email) }}" readonly aria-label="Email">
                </span>
                <a class="ml-auto flex-none font-semibold text-link hover:underline" href="{{ route('password.request') }}">Not you?</a>
            </div>

            <div class="mt-6">
                <x-password-input name="password" label="New password" autocomplete="new-password" required autofocus x-model="pw" describedby="password-rules" />
                <x-password-rules model="pw" />
            </div>

            <div class="mt-4">
                <x-password-input name="password_confirmation" label="Confirm new password" autocomplete="new-password" required />
            </div>

            <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Reset password</button></p>
            <p class="type-caption mt-3 text-center text-text-2">Resetting signs you out everywhere else, too.</p>
        </form>
    @endif
@endsection
