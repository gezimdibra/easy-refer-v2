@extends('layouts.marketing')

@section('title', 'FAQ: Easy Refer')

@section('meta_description', 'Answers about Easy Refer: how QR code referrals work, what it costs, and how your data is handled.')

@section('content')
    <section class="shell section-pad">
        <h1 class="type-title-1 text-text-1">FAQ</h1>

        <div class="mt-8 max-w-[640px]">
            @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h2'])
        </div>
    </section>
@endsection
