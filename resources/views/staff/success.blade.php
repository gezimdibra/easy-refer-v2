@extends('layouts.flow')

@section('title', 'Confirmed | Easy Refer')
@section('flow_label', 'Counter')
@section('main_class', 'mt-3')

@section('content')
    {{-- R8b, the staff result screen (Section 3.5): shown once,
         immediately after the staff member's own confirm press.
         A later scan of the same code lands on the resume state
         ("Already redeemed" / "Already claimed") instead; the two
         wordings are never interchangeable. --}}
    @include('partials.success-flood', [
        'rewardText' => $rewardText,
        'businessName' => $businessName,
        'business' => $business ?? null,
        'outcome' => $outcome ?? null,
        'confirmedAt' => $confirmedAt,
        'note' => 'Show this screen if anyone asks.',
    ])
@endsection
