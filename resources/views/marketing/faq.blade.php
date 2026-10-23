@extends('layouts.marketing')

@section('title', 'FAQ: Easy Refer')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-12">
        <h1 class="text-4xl font-bold text-text-1">FAQ</h1>

        <div class="mt-8 max-w-[720px] space-y-3">
            @foreach ($faqs as $faq)
                <div x-data="{ open: false }" class="card">
                    <h2>
                        <button type="button" class="flex w-full items-center justify-between text-left text-lg font-semibold text-text-1" :aria-expanded="open.toString()" @click="open = ! open">
                            {{ $faq['q'] }}
                            <span aria-hidden="true" x-text="open ? '−' : '+'"></span>
                        </button>
                    </h2>
                    <div x-show="open" x-cloak>
                        <p class="mt-3 text-base text-text-2">{{ $faq['a'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endsection
