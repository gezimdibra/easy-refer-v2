@extends('layouts.app')

@section('title', 'Add a customer')


@section('content')
    <h1 class="type-title-2 text-text-1">Add a customer</h1>
    <p class="type-body mt-2 max-w-[68ch] text-text-2">Add a regular who agreed to get their referral link by text. We send one text so they can confirm.</p>

    @include('owner.customers.form', ['mode' => 'create'])
@endsection
