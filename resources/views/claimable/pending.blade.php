@extends('layouts.claimable')
{{-- P4: the claim block is REPLACED, not disabled (§10.3 C32) —
     there is no claim button on this view for anyone. --}}
@section('title', 'Claim being checked for '.$page->shop_name)
@section('content')
    <p class="preview-strip" data-copy="C01">{{ \App\Support\ClaimableCopy::c01($page) }}</p>

    @if ($isRequester)
        <p data-copy="C31">{{ \App\Support\ClaimableCopy::c31() }}</p>
    @else
        <p data-copy="C32">{{ \App\Support\ClaimableCopy::c32() }}</p>
    @endif

    @foreach (\App\Support\ClaimableCopy::costBlock() as $id => $line)
        <p data-copy="{{ $id }}">{{ $line }}</p>
    @endforeach

    <footer>
        <form method="POST" action="{{ route('claimable.remove', ['token' => $token]) }}">
            @csrf
            <button type="submit" class="text-action" data-copy="C34">{{ \App\Support\ClaimableCopy::c34() }}</button>
        </form>
        <p data-copy="C03">{{ \App\Support\ClaimableCopy::c03($page) }}</p>
    </footer>
@endsection
