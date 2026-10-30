@props(['state'])
{{-- R4-U1: the customer state pill (FlowState::pill). Rendered in
     the flow chrome row (the step-indicator slot) on show screens
     and above the headline on state screens, so the state reads
     before the body text does. --}}
@php($s = \App\Support\FlowState::pill($state))
<span class="pill state-pill {{ $s['pill'] }}">{{ $s['label'] }}</span>
