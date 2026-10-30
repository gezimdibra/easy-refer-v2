@extends('emails.layout')

@section('title', 'Unlock your Easy Refer account')

@section('content')
    {{-- EU-S2: on the custom shell; every sentence is the
         notification's shipped copy, verbatim. --}}
    <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">Unlock your Easy Refer account</h1>
    @foreach ($lines as $i => $line)
        <p class="em-text" style="margin: {{ $i === 0 ? '16px' : '16px' }} 0 0; font-size: 16px; line-height: 26px; color: #16130e">{{ $line }}</p>
        @if ($i === 1)
            @include('emails.partials.button', ['url' => $url, 'label' => 'Unlock my account'])
            <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Or open this link: <a href="{{ $url }}" class="em-a" style="color: #0d0b08; text-decoration: underline">{{ $url }}</a></p>
        @endif
    @endforeach
@endsection
