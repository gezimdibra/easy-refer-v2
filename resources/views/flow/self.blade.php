@extends('layouts.flow')

@section('title', ($variant === 'owner_phone' ? 'Use a different number' : 'You cannot refer yourself').' | Easy Refer')
@section('flow_label', 'Claim')

@section('content')
    {{-- The self-referral stop (spec Section 3 Step 8, inventory
         REF-SELF): the claim earns nothing, stated honestly, with
         the one useful action offered. --}}
    <h1 class="text-[32px] font-bold leading-[38px]">
        @if ($variant === 'owner_phone')
            That is the business's own number
        @else
            You cannot refer yourself
        @endif
    </h1>

    <p class="mt-2 text-text-2">
        @if ($variant === 'owner_phone')
            This number belongs to {{ $business->name }}, so no reward can be earned with it. Please use the customer's own mobile number.
        @else
            This is your own referral link. Share it with a friend to earn your reward.
        @endif
    </p>

    @if ($variant === 'owner_phone')
        <a class="btn btn-flow btn-primary w-full mt-5" href="{{ $returnUrl }}">Use a different number</a>
    @else
        <a class="btn btn-flow btn-primary w-full mt-5" href="{{ route('refer.share') }}">Share your link</a>
    @endif
@endsection
