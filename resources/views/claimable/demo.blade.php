@extends('layouts.claimable')
{{-- The demo context (H3/H4): the customer flow's screens wearing
     the shop's name. Every screen carries the Demo chip; the actor
     is the Example customer; the phone step collects nothing and
     sends nothing; the share action is a stub that explains. This
     context writes no flow rows, ever. --}}
@section('title', 'Demo for '.$page->shop_name)
@section('content')
    <p class="preview-strip" data-copy="C01">{{ \App\Support\ClaimableCopy::c01($page) }}</p>
    <p><span class="demo-chip" data-copy="C11">{{ \App\Support\ClaimableCopy::c11() }}</span></p>
    <p data-copy="C10">{{ \App\Support\ClaimableCopy::c10() }}</p>

    <section>
        <p><span class="demo-chip">{{ \App\Support\ClaimableCopy::c11() }}</span>
           <span data-copy="C12">{{ \App\Support\ClaimableCopy::c12() }}</span></p>
        <h2>{{ $page->shop_name }}</h2>
        <p>{{ $page->starter_reward_text }}</p>
        <p>The {{ \App\Support\ClaimableCopy::c12() }} scans the poster and lands here.</p>
    </section>

    <section>
        <p><span class="demo-chip">{{ \App\Support\ClaimableCopy::c11() }}</span></p>
        <label>Phone number</label>
        <input type="tel" value="555-0100" disabled>
        <p data-copy="C13">{{ \App\Support\ClaimableCopy::c13() }}</p>
    </section>

    <section>
        <p><span class="demo-chip">{{ \App\Support\ClaimableCopy::c11() }}</span></p>
        <button type="button" class="btn" disabled>Send it to a friend</button>
        <p data-copy="C14">{{ \App\Support\ClaimableCopy::c14() }}</p>
    </section>

    <form method="POST" action="{{ route('claimable.demo.complete', ['token' => $token]) }}">
        @csrf
        <button type="submit" class="btn">Back to your preview</button>
    </form>

    <footer>
        <p data-copy="C03">{{ \App\Support\ClaimableCopy::c03($page) }}</p>
    </footer>
@endsection
