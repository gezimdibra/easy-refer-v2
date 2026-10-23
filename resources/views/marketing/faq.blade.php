@extends('layouts.marketing')

@section('title', 'FAQ: Easy Refer')

@section('meta_description', 'Answers about Easy Refer: how QR code referrals work, what it costs, and how your data is handled.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h1 class="text-[32px] leading-[38px] font-bold text-text-1">FAQ</h1>

        <div class="mt-8 max-w-[640px]">
            @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h2'])
        </div>
    </section>
@endsection
