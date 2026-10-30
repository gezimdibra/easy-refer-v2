@php($errorLayout = \App\Support\ErrorPageContext::layout())
@extends($errorLayout)

@section('theme_lock', 'light')

@section('title', 'Something went wrong | Easy Refer')

@section('meta_description', 'Something went wrong on Easy Refer. Please try again in a few minutes.')

@section('content')
    @include('errors.partials.body', [
        'errorCode' => 500,
        'errorIcon' => 'circle-alert',
        'errorTint' => 'error',
        'errorHeading' => 'Something went wrong',
        'errorLede' => 'Something went wrong on our side while loading this page. Please try again in a few minutes.',
    ])
@endsection
