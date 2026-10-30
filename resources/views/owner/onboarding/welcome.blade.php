@extends('layouts.onboarding')

@section('title', 'Get started')

@section('content')
    {{-- OB-0 Welcome (design pack Section 4.1; copy in Section 4
         is PROPOSED, Amendment 28, listed for coordinator
         ratification in the lane report). Job: teach the loop
         and the size of the job. Length frozen by benchmark
         O-5: it frames, it does not educate. --}}
    <h1 class="type-title-2 text-text-1">Your customers are about to do your marketing.</h1>

    <ol class="mt-6 space-y-3 text-text-1">
        <li><span class="font-semibold">1.</span> A customer scans your code and sends it to a friend.</li>
        <li><span class="font-semibold">2.</span> The friend comes in. A visit like that is what you pay for, and your first 5 are free.</li>
        <li><span class="font-semibold">3.</span> The friend gets a reward at the visit, and the customer who sent them earns one too.</li>
    </ol>

    {{-- ACT-WEL-01 (activation redesign, census C-01): the
         card clause is retired — the code works at the end
         with no card taken. The "less than 2 minutes" claim
         is the owner's override (2026-10-29) and stays.
         RS2-2 merge (2026-10-30): the step count is six —
         the Location state joined the flow. --}}
    <p class="type-body mt-6 text-text-2">Setting up takes less than 2 minutes: six short steps. Your code works at the end. Your first 5 visits are free, and adding a card keeps your code going after that.</p>

    {{-- O-2: the five step names as a preview of the job. --}}
    <p class="type-caption mt-4 text-text-2">
        @foreach (\App\Support\Onboarding::STEPS as $step)
            <span>{{ \App\Support\Onboarding::LABELS[$step] }}</span>@unless ($loop->last) <span aria-hidden="true"> · </span> @endunless
        @endforeach
    </p>

    <p class="mt-8 flex flex-wrap items-center gap-4">
        <a class="btn btn-primary" href="{{ route('onboarding.step', $firstOpen) }}">Start setup</a>
        <a class="text-action" href="{{ route('dashboard') }}">Not now</a>
    </p>
@endsection
