@extends('layouts.claimable')
{{-- The standalone preview poster print: same shared composition
     as the on-page poster block, never a fork. --}}
@section('title', 'Poster preview for '.$page->shop_name)
@section('content')
    <p data-copy="C08">{{ \App\Support\ClaimableCopy::c08() }}</p>
    @include('claimable._poster', ['page' => $page, 'token' => $token])
@endsection
