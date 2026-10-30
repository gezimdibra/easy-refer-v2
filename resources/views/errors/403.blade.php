@php($errorLayout = \App\Support\ErrorPageContext::layout())
@extends($errorLayout)

@section('theme_lock', 'light')

@section('title', 'No access to this page | Easy Refer')

@section('meta_description', 'This Easy Refer page is only available to people with the right access for it.')

@section('content')
    @include('errors.partials.body', [
        'errorCode' => 403,
        'errorIcon' => 'lock',
        'errorTint' => 'neutral',
        'errorHeading' => 'You do not have access to this page',
        'errorLede' => 'This page is only available to people with the right access for it.',
    ])
@endsection
