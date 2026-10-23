@extends('layouts.admin')

@section('title', 'Two-factor check')

@section('notices_summary_only', '1')

@section('content')
    <div class="card mx-auto max-w-[480px]">
        <h1 class="text-[28px] font-bold">Two-factor check</h1>
        <p class="mt-1 text-text-2">Enter the code from your authenticator app, or one of your recovery codes.</p>

        <form method="POST" action="{{ route('admin.two-factor.challenge.store') }}" class="mt-4">
            @csrf

            <label for="code">Code</label>
            <input id="code" name="code" type="text" inputmode="numeric" required autocomplete="one-time-code" autofocus
                aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}"
                @if ($errors->has('code')) aria-describedby="code-error" @endif>
            @error('code') <p class="form-error" id="code-error">{{ $message }}</p> @enderror

            <p class="mt-4"><button type="submit" class="btn btn-primary">Continue</button></p>
        </form>
    </div>
@endsection
