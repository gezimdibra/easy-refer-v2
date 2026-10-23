@extends('layouts.app')

@section('title', 'Reviews')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-[28px] font-bold">Reviews</h1>

    @include('owner.settings.nav')

    <form method="POST" action="{{ route('settings.reviews.update') }}" class="card">
        @csrf

        <label for="google_review_url">Google review link</label>
        <input id="google_review_url" name="google_review_url" type="url" value="{{ old('google_review_url', $business->google_review_url) }}"
            aria-invalid="{{ $errors->has('google_review_url') ? 'true' : 'false' }}"
            @if ($errors->has('google_review_url')) aria-describedby="google_review_url-error google_review_url-help" @else aria-describedby="google_review_url-help" @endif>
        @error('google_review_url') <p class="form-error" id="google_review_url-error">{{ $message }}</p> @enderror
        <p class="form-help" id="google_review_url-help">Paste your Google review link. Customers who redeem or claim a reward see a button to it. We never ask anyone for a positive review.</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
@endsection
