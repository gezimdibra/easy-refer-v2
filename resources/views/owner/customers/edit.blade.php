@extends('layouts.app')

@section('title', 'Edit customer')


@section('content')
    <h1 class="type-title-2 text-text-1">Edit customer</h1>
    <p class="type-body mt-2 max-w-[68ch] text-text-2">Fix a name you typed wrong. The change shows everywhere this customer appears.</p>

    @include('owner.customers.form', ['mode' => 'edit'])
@endsection
