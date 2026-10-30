@props(['state', 'detail' => null])
{{-- R4-U1: the staff state band (FlowState::band), the pill grown
     to counter distance. Full-bleed at the top of the card (the
     card's 20px padding is cancelled on this element only). --}}
@php($s = \App\Support\FlowState::band($state))
<p class="state-band {{ $s['band'] }}"><span>{{ $s['label'] }}</span>@if ($detail)<span aria-hidden="true"> · </span><span>{{ $detail }}</span>@endif</p>
