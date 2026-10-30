@extends('layouts.app')

@section('title', 'Edit thank-you text')

@section('content')
    {{-- ID-02 (Amendment 48): the scoped body-only editor for a
         recognition draft. TR-11 heads it: there is no
         audience here, there is a person. The generic composer
         never opens this draft. --}}
    <h1 class="type-title-2 text-text-1">Edit thank-you text</h1>

    <div class="panel mt-5">
        <p class="font-medium text-text-1">Sending to: {{ $recipientName }} (1 customer)</p>

        @if ($standingsChanged)
            <div class="banner banner-warning mt-4">
                <div>
                    <p class="font-semibold">The standings changed</p>
                    <p class="type-body-sm mt-1">The count is now {{ $currentCount }}. Your text below is your own wording. You can keep it, edit it, or use the updated text.</p>
                    <form method="POST" action="{{ route('campaigns.recognition.update', $campaign) }}" class="mt-3" data-loading>
                        @csrf
                        <input type="hidden" name="use_updated" value="1">
                        <button type="submit" class="btn btn-secondary">Use the updated text</button>
                    </form>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('campaigns.recognition.update', $campaign) }}" class="mt-4" data-loading>
            @csrf
            <label for="body">Message</label>
            <textarea id="body" name="body" rows="5">{{ old('body', $campaign->body) }}</textarea>
            @error('body') <p class="form-error">{{ $message }}</p> @enderror
            <p class="form-help">The link to their referral page and the opt-out line are added when the text sends. They are not part of the message you write here.</p>
            <p class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary">Save and review</button>
                <a class="btn btn-secondary" href="{{ route('campaigns.confirm', $campaign) }}">Back to review</a>
            </p>
        </form>
    </div>
@endsection
