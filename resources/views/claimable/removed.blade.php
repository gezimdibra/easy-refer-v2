@extends('layouts.claimable')
{{-- P8 / R0-R4: the whole page is replaced by the removed line,
     rendered from the bare register row after purge (no shop data
     beyond what the line itself carries). C36 renders only at the
     request moment (R0); C37 only for a claim killed mid-flight. --}}
@section('title', 'Preview taken down')
@section('content')
    <p>{{ $line }}</p>
    @if ($confirmation)
        <p>{{ $confirmation }}</p>
    @endif
@endsection
