@php($errorLayout = \App\Support\ErrorPageContext::layout())
@extends($errorLayout)

@section('theme_lock', 'light')

@section('title', 'Too many requests | Easy Refer')

@section('meta_description', 'You have sent a lot of requests to Easy Refer in a short time. Please wait a moment and try again.')

@section('content')
    @include('errors.partials.body', [
        'errorCode' => 429,
        'errorIcon' => 'trending-up',
        'errorTint' => 'amber',
        'errorHeading' => 'Too many requests',
        'errorLede' => 'You have sent a lot of requests in a short time, so we need you to slow down for a moment. Wait a minute, then try again.',
    ])
@endsection
