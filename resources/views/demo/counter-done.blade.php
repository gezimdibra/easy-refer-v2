@extends('layouts.demo')

@section('title', 'Reward redeemed | Easy Refer')
@section('flow_label', 'Counter')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => null])
@endsection

@section('content')
    {{-- S5's success state: the real already-redeemed receipt
         shape with the DEMO device riding it. Re-presenting a
         spent token lands here too: the single-use rule is
         felt, not described. --}}
    @include('partials.success-flood', [
        'rewardText' => $business->friend_reward_text ?? 'Reward redeemed',
        'businessName' => $business->name,
        'business' => $business,
        'outcome' => 'Reward redeemed',
        'confirmedAt' => $referral->redeemed_at,
        'note' => 'That code can only be used once.',
    ])

    <p class="type-caption mt-3 text-center text-text-2">Demo reward. Not valid at any real shop.</p>
@endsection

@section('action')
    <a class="btn btn-flow btn-primary w-full" href="{{ route('demo.coda') }}">See what the owner sees</a>
@endsection
