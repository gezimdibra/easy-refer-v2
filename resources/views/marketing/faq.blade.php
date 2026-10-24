@extends('layouts.marketing')

@section('title', 'FAQ: Easy Refer')

@section('meta_description', 'Answers about Easy Refer: how QR code referrals work, what it costs, and how your data is handled.')

@section('content')
    <section class="shell section-pad">
        <h1 class="type-title-1 text-text-1">FAQ</h1>

        <div class="mt-8 max-w-[640px]">
            {{-- Ruling 5 (08d conformance): the page renders the
                 list flat, every answer in the open; the accordion
                 mode belongs to the section-embedded lists. --}}
            @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h2', 'mode' => 'flat'])
        </div>

        {{-- S-05 (release 08b): the page closes inside main with
             the standard support fallback and the primary action,
             so the question journey ends somewhere instead of at
             the footer (ST-3: every page closes with its next
             action). --}}
        <div class="mt-16 max-w-[640px]">
            <h2 class="type-title-1 text-text-1">Still have a question?</h2>
            <p class="type-body mt-3 text-text-2">Email us at <a class="font-semibold text-brand-700 underline" href="mailto:support@easy-refer.com">support@easy-refer.com</a> and we will get back to you.</p>
            <p class="mt-6"><a class="btn btn-primary" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>
@endsection
