@extends('layouts.claimable')
{{-- P5 tombstone: no shop data beyond the name — no poster, no
     demo, no address. The contested path (C39) is the only action. --}}
@section('title', 'Page claimed')
@section('content')
    <p data-copy="C38">{{ \App\Support\ClaimableCopy::c38($page) }}</p>
    <p data-copy="C39">{{ \App\Support\ClaimableCopy::c39($page) }}</p>
    <p><a href="{{ route('claimable.contested', ['token' => request()->route('token')]) }}">Tell us here</a></p>
    @if (session('claimable.contested_received'))
        <p>Thank you. We will check this with the shop.</p>
    @endif
@endsection
