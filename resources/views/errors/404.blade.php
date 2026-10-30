@php($errorLayout = \App\Support\ErrorPageContext::layout())
@extends($errorLayout)

@section('theme_lock', 'light')

@section('title', 'Page not found | Easy Refer')

@section('meta_description', 'The page you were looking for could not be found on Easy Refer.')

@section('content')
    @include('errors.partials.body', [
        'errorCode' => 404,
        'errorIcon' => 'search',
        'errorTint' => 'neutral',
        'errorHeading' => 'Page not found',
        'errorLede' => 'We could not find the page you were looking for. The link may be out of date, or the address may have been typed incorrectly.',
    ])
@endsection
