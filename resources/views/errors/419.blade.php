@php($errorLayout = \App\Support\ErrorPageContext::layout())
@extends($errorLayout)

@section('theme_lock', 'light')

@section('title', 'Your session expired | Easy Refer')

@section('meta_description', 'Your Easy Refer session expired. Please try again.')

@section('content')
    @include('errors.partials.body', [
        'errorCode' => 419,
        'errorIcon' => 'log-out',
        'errorTint' => 'amber',
        'errorHeading' => 'Your session expired',
        'errorLede' => 'For your security, this form expired because the page was open too long. Please try again.',
    ])
@endsection
