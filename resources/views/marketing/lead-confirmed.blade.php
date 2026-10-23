@extends('layouts.marketing')

@section('title', 'You are on the list | Easy Refer')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-12">
        <div class="max-w-[720px]">
            <h1 class="text-4xl font-bold text-text-1">You are on the list</h1>
            <p class="mt-4 text-lg text-text-2">Your subscription is confirmed.</p>
            <p class="mt-6"><a class="font-semibold text-brand-700 underline" href="{{ route('home') }}">Back to the home page</a></p>
        </div>
    </section>
@endsection
