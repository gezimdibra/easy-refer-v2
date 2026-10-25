@extends('layouts.app')

@section('title', 'Reviews')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Reviews</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Reviews</span>
    </nav>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <form method="POST" action="{{ route('settings.reviews.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading>
        @csrf

        <label for="google_review_url">Google review link</label>
        <input id="google_review_url" name="google_review_url" type="url" value="{{ old('google_review_url', $business->google_review_url) }}"
            aria-invalid="{{ $errors->has('google_review_url') ? 'true' : 'false' }}"
            @if ($errors->has('google_review_url')) aria-describedby="google_review_url-error google_review_url-help" @else aria-describedby="google_review_url-help" @endif>
        @error('google_review_url') <p class="form-error" id="google_review_url-error">{{ $message }}</p> @enderror
        <p class="form-help" id="google_review_url-help">Paste your Google review link. Customers who redeem or claim a reward see a button to it. We never ask anyone for a positive review.</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
