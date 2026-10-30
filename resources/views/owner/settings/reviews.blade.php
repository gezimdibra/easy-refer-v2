@extends('layouts.app')

@section('title', 'Reviews')


@section('content')
    <h1 class="type-title-2 text-text-1">Reviews</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">Where happy customers review you in public.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    {{-- R2 SET-REVIEWS (design section 11): the state row
         answers "am I done here?" before the field. --}}
    <div class="panel mb-4">
        <div class="flex flex-wrap items-center gap-3">
            @if (filled($business->google_review_url))
                <span class="pill pill-success">Link set</span>
                <span class="min-w-0 break-all text-text-1">{{ $business->google_review_url }}</span>
            @else
                <span class="pill pill-neutral">Not set (optional)</span>
            @endif
        </div>
        {{-- REVIEW-05 (Amendment 48): the mechanism line,
             carrying the cooldown's concrete number so the
             sentence stays true in every state. It replaces
             the pre-cooldown line, which the 90-day rule made
             false. --}}
        <p class="type-body-sm mt-2 text-text-2">Customers see this link on their receipt after a visit, and again when they claim a reward, at most once every {{ $reviewAskCooldownDays ?? 90 }} days for each customer.</p>
        @if (filled($business->google_review_url))
            {{-- REVIEW-03 + REVIEW-04: our counts, and the
                 honesty line that bounds what we know. The
                 pair renders together or not at all. --}}
            <p class="type-body-sm mt-2 text-text-2">In the last 30 days, {{ $reviewAskCounts['shown'] }} {{ $reviewAskCounts['shown'] === 1 ? 'customer' : 'customers' }} saw the review link on their receipt, and {{ $reviewAskCounts['clicked'] }} tapped it.</p>
            <p class="type-body-sm mt-2 text-text-2">Whether they left a review is on Google. We cannot see it here.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('settings.reviews.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading>
        @csrf

        <label for="google_review_url">Google review link (optional)</label>
        <input id="google_review_url" name="google_review_url" type="url" value="{{ old('google_review_url', $business->google_review_url) }}"
            aria-invalid="{{ $errors->has('google_review_url') ? 'true' : 'false' }}"
            @if ($errors->has('google_review_url')) aria-describedby="google_review_url-error google_review_url-help" @else aria-describedby="google_review_url-help" @endif>
        @error('google_review_url') <p class="form-error" id="google_review_url-error">{{ $message }}</p> @enderror
        <p class="form-help" id="google_review_url-help">Paste your Google review link if you have one. Leave it empty if you don't: customers just won't see the review link on their receipts, and you can add it any time. We never ask anyone for a positive review.</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
