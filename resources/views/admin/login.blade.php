@extends('layouts.admin')

@section('title', 'Platform sign in')

@section('notices_summary_only', '1')

@section('content')
    <div class="card mx-auto max-w-[480px]">
        <h1 class="text-2xl font-bold">Platform sign in</h1>
        <p class="mt-1 text-text-2">This area is for the Easy Refer team.</p>

        <form method="POST" action="{{ route('admin.login.store') }}" class="mt-4">
            @csrf

            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                @if ($errors->has('email')) aria-describedby="email-error" @endif>
            @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror

            <x-password-input name="password" label="Password" required />

            <p class="mt-4"><button type="submit" class="btn btn-primary">Sign in</button></p>
        </form>
    </div>
@endsection
