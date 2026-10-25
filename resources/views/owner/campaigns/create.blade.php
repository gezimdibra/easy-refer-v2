@extends('layouts.app')

@section('title', 'New text campaign')

@section('content')
    {{-- Compose (spec Section 10.3; DASH-09, DASH-10): the owner
         types the body only. The prefix and the STOP suffix are
         system parts, shown locked in the preview, and every
         number on this page (segments, recipients, cost) is
         computed by the server: the client never does money
         math. Sending saves a draft and goes to the confirm
         screen; nothing sends from here. --}}
    <h1 class="type-title-2 text-text-1">New text campaign</h1>

    @if (! $available)
        <div class="panel mt-5">
            <p class="text-text-1">Text campaigns are not available right now.</p>
        </div>
    @else
        <form method="POST" action="{{ route('campaigns.store') }}" class="panel mt-5" data-loading
            x-data="{
                body: @js(old('body', $draft?->body ?? '')),
                rendered: @js($figures['rendered_body']),
                segments: {{ $figures['segments'] }},
                encoding: @js($figures['encoding']),
                totalCents: {{ $figures['total_cents'] }},
                recipients: {{ $figures['recipients'] }},
                timer: null,
                refresh() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => {
                        fetch(@js($estimateUrl), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ body: this.body }),
                        }).then(r => r.json()).then(d => {
                            this.rendered = d.rendered_body;
                            this.segments = d.segments;
                            this.encoding = d.encoding;
                            this.totalCents = d.total_cents;
                            this.recipients = d.recipients;
                        }).catch(() => {});
                    }, 350);
                }
            }">
            @csrf
            @if ($draft)
                <input type="hidden" name="draft_id" value="{{ $draft->id }}">
            @endif

            <label class="form-label" for="body">Message</label>
            <textarea id="body" name="body" rows="4" class="form-input form-input-app"
                x-model="body" @input="refresh()">{{ old('body', $draft?->body ?? '') }}</textarea>
            @error('body')
                <p class="mt-2 text-danger">{{ $message }}</p>
            @enderror

            <p class="type-body-sm mt-4 text-text-2">Your message as customers will receive it:</p>
            <p class="mt-1 rounded-md bg-surface-1 px-3 py-2 text-text-1" x-text="rendered"></p>

            <p class="mt-4 font-medium text-text-1" x-text="'Sending to ' + recipients + ' customers who agreed to receive messages.'">Sending to {{ $figures['recipients'] }} customers who agreed to receive messages.</p>
            <p class="type-body-sm mt-1 text-text-2">Only customers who agreed to receive text messages, and who have not opted out, will receive this.</p>
            @php($exclusions = $audience['exclusions'])
            @if (array_sum($exclusions) > 0)
                <p class="type-body-sm mt-1 text-text-2">Not counted: {{ $exclusions['owner_asserted_unconfirmed'] }} added by you and not yet confirmed, {{ $exclusions['opted_out'] }} opted out, {{ $exclusions['no_consent'] }} with no consent on file.</p>
            @endif

            <p class="mt-3 text-text-1">
                <span x-text="segments">{{ $figures['segments'] }}</span> segment(s) per customer ·
                <span x-text="'$' + (totalCents / 100).toFixed(2)">{{ '$'.number_format($figures['total_cents'] / 100, 2) }}</span>
                for <span x-text="recipients">{{ $figures['recipients'] }}</span> customers
                <span x-show="encoding === 'Unicode'" x-cloak>· Unicode message (emoji and some symbols count differently)</span>
            </p>

            @if ($figures['recipients'] === 0)
                <p class="mt-4 text-text-1">No customers have confirmed yet, so there is nobody to send to. Customers confirm by replying YES to the confirmation text, or by agreeing when they join.</p>
            @else
                <p class="mt-5">
                    <button type="submit" class="btn btn-primary">Send to all customers</button>
                </p>
            @endif
        </form>
    @endif
@endsection
