@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h1 class="text-2xl font-bold">Settings</h1>

    @include('owner.settings.nav')

    <div class="card">
        <h2 class="text-xl font-semibold">Setup checklist</h2>
        @if ($business->checklist_dismissed_at !== null)
            <p class="mt-1 text-text-2">Checklist is hidden. Show it again any time.</p>
            <form method="POST" action="{{ route('settings.checklist.reset') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-secondary">Show the setup checklist again</button>
            </form>
        @else
            <p class="mt-1 text-text-2">Checklist is showing on your Overview.</p>
            <p class="mt-3"><a class="btn btn-secondary" href="{{ route('dashboard') }}">Open the checklist</a></p>
        @endif
    </div>
@endsection
