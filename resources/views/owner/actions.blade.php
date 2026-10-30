@extends('layouts.app')

@section('title', 'Action Center')

@section('content')
    {{-- The Action Center (RK-01; copy Amendment 22 AC-01,
         AC-02): every state waiting on the owner, one row per
         state, sorted by urgency, each with the deep link to
         the page that resolves it and a dismissal that sticks
         until the state changes. When nothing is open, the
         quiet healthy line renders alone: silence = healthy. --}}
    <h1 class="type-title-2 text-text-1">Action Center</h1>
    <p class="type-body-sm mt-1 text-text-2">Everything that needs you, in one place.</p>

    @if ($items === [])
        <div class="card mt-5">
            {{-- AC-02 renders only when no state is open at
                 all; when rows stand merely dismissed the page
                 carries AC-10's quiet line instead (G3, ruling
                 Q3) — a dismissal never manufactures "good
                 standing". --}}
            @if ($quiet)
                <p class="text-text-1">Nothing new needs you right now.</p>
            @else
                <p class="text-text-1">✓ Account in good standing</p>
            @endif
        </div>
    @else
        <div class="mt-5 space-y-3">
            @foreach ($items as $item)
                {{-- T2R-01 (F-05 class): on a phone this row
                     stacks, in the QR poster list's pattern —
                     the line at full width on top, the action +
                     Dismiss row beneath it. The shipped
                     flex-wrap + min-w-0 flex-1 line never
                     wrapped: the line crushed into a ~98px
                     column and the buttons painted over the
                     text. At sm and up the row is the desktop
                     row again, unchanged. Pinned by
                     ActionCenterGeometryTest. --}}
                <div class="card flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <p class="text-text-1 sm:min-w-0 sm:flex-1">{{ $item['line'] }}</p>
                    <span class="flex flex-wrap items-center gap-2">
                        @if (! empty($item['post_url']))
                            {{-- Idea Train 1 prompts: the action
                                 POSTs into the preset path (the
                                 click creates a draft and lands
                                 on confirm; nothing sends from
                                 the Action Center). --}}
                            <form method="POST" action="{{ $item['post_url'] }}" data-loading>
                                @csrf
                                @foreach (($item['post_fields'] ?? []) as $field => $value)
                                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                                @endforeach
                                <button type="submit" class="btn btn-secondary">{{ $item['action_label'] }}</button>
                            </form>
                        @else
                            <a class="btn btn-secondary" href="{{ $item['url'] }}">{{ $item['action_label'] }}</a>
                        @endif
                        {{-- The dismiss form carries the row's
                             position, never the item key: keys
                             mirror internal state names and the
                             raw status word never renders. A
                             non-dismissible row (G3: past due)
                             renders no Dismiss control at all. --}}
                        @if ($item['dismissible'])
                            <form method="POST" action="{{ route('actions.dismiss', $loop->index) }}" data-loading>
                                @csrf
                                {{-- An item may carry its own
                                     dismiss label (ID-06's
                                     [SC-06] "Not now"); every
                                     other row keeps "Dismiss". --}}
                                <button type="submit" class="btn btn-ghost">{{ $item['dismiss_label'] ?? 'Dismiss' }}</button>
                            </form>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif
@endsection
