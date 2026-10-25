@extends('layouts.app')

@section('title', 'Review and send')

@section('content')
    {{-- Confirm (spec Section 10.3; DASH-09): the only send
         path. The figures on this page were recomputed from
         ground truth at render and stamped on the draft; the
         send compares against them and bounces back here if
         they moved. The cap sentence is required honesty: the
         $99 cap covers visit charges only. --}}
    <h1 class="type-title-2 text-text-1">Review and send</h1>

    <div class="panel mt-5">
        <p class="type-body-sm text-text-2">Your message as customers will receive it:</p>
        <p class="mt-1 rounded-md bg-surface-1 px-3 py-2 text-text-1">{{ $figures['rendered_body'] }}</p>

        <p class="mt-4 font-medium text-text-1">Sending to {{ $figures['recipients'] }} customers who agreed to receive messages.</p>
        <p class="type-body-sm mt-1 text-text-2">Only customers who agreed to receive text messages, and who have not opted out, will receive this.</p>

        {{-- Preflight (LV-01; CAMP-21): who will NOT get this
             text, stated here where the send decision happens
             (the breakdown the composer has always shown,
             CAMP-09, in its decision-moment home). --}}
        @php($exclusions = $audience['exclusions'])
        @if (array_sum($exclusions) > 0)
            <h2 class="type-title-3 mt-4 text-text-1">Before you send</h2>
            <p class="type-body-sm mt-1 text-text-2">Not counted: {{ $exclusions['owner_asserted_unconfirmed'] }} added by you and not yet confirmed, {{ $exclusions['opted_out'] }} opted out, {{ $exclusions['no_consent'] }} with no consent on file.</p>
        @endif

        <ul class="mt-4 space-y-1 text-text-1">
            <li>{{ $figures['segments'] }} segments per customer</li>
            <li>{{ $segmentPriceCents }} cents per segment</li>
            <li>{{ $figures['recipients'] }} customers</li>
            <li class="font-semibold">Total: {{ '$'.number_format($figures['metered_cents'] / 100, 2) }}</li>
        </ul>

        @if ($figures['segments'] > 3)
            <p class="mt-3 text-text-1">This is a long message: {{ $figures['segments'] }} segments per customer. Longer messages cost more per customer.</p>
        @endif

        @if ($figures['fits_today'] < $figures['recipients'])
            <p class="mt-3 text-text-1">You can send {{ $figures['fits_today'] }} of {{ $figures['recipients'] }} today. The rest will not be sent.</p>
        @endif

        <p class="mt-3 text-text-2">Text charges join your monthly Square charge as their own line. The $99 monthly cap covers visit charges only, so a month with texts can total more than $99.</p>

        {{-- Scoped confirmation (RS-14; GEN-05 supersedes the
             CAMP-01 "Send now" on this screen): the button is
             labelled by the verb and restates the scope with
             the cost and the irreversibility, directly above
             the button it scopes. --}}
        <p class="mt-5 font-medium text-text-1">Total {{ '$'.number_format($figures['metered_cents'] / 100, 2) }} for {{ $figures['recipients'] }} customers. Once sent, this text cannot be unsent.</p>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('campaigns.send', $campaign) }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-primary">Send to {{ $figures['recipients'] }} customers</button>
            </form>
            <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
        </div>
    </div>
@endsection
