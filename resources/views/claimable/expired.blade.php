@extends('layouts.claimable')
{{-- P7/P9: the whole page is replaced by the ended line. The name
     is retained on the register row precisely so this sentence
     can render after the snapshot purge. --}}
@section('title', 'Preview ended')
@section('content')
    <p data-copy="C40">{{ \App\Support\ClaimableCopy::c40($page) }}</p>
    <p><a class="btn" href="{{ route('register') }}">Start free</a></p>
@endsection
