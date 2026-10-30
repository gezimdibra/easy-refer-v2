@extends('emails.layout')

@section('title', $headline)

@section('content')
    {{-- EU-S2: the notice-grammar shell view shared by the
         action-less security notifications (email changed, login
         attempt alert) and password-changed (which passes its
         action). Every sentence is the notification's shipped
         copy, verbatim, passed in as $lines. --}}
    <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">{{ $headline }}</h1>
    @foreach ($lines as $line)
        <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">{{ $line }}</p>
    @endforeach
    @isset($url)
        @include('emails.partials.button', ['url' => $url, 'label' => $actionLabel])
        <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Or open this link: <a href="{{ $url }}" class="em-a" style="color: #0d0b08; text-decoration: underline">{{ $url }}</a></p>
    @endisset
@endsection
