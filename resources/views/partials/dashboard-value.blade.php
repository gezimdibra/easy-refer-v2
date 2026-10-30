{{--
    The Value panel (VAL-01 to VAL-03 verbatim), included by
    the Overview directly under the chart in the standing state
    (OV-3: the growth line and its money meaning read together)
    and last in the legacy and zero states, as today.

    P1 SPEND-DEFAULT (critic report 2026-10-29; Amendment 57
    family): every estimated-sales figure in this panel is
    computed from the owner's typical spend per visit, so the
    estimate lines render ONLY when the owner has set one
    (Business::hasTypicalSpend). Unset, the panel counts the
    visits and the fees (both real) and asks for the figure
    instead of asserting a sales estimate based on a spend
    the owner never gave.
--}}
<div class="card mt-4">
    <h2 class="type-title-3 text-text-1">What Easy Refer brought you</h2>
    @if ($value['verified_visits'] > 0)
        <ul class="mt-2 space-y-1 text-base">
            <li>{{ $value['verified_visits'] }} {{ $value['verified_visits'] === 1 ? 'visit' : 'visits' }} counted this month</li>
            @if ($business->hasTypicalSpend())
                <li>About {{ '$'.number_format($value['estimated_sales_cents'] / 100, 2) }} in estimated sales, based on your typical spend per visit (estimate)</li>
            @endif
            <li>Visit fees so far this month: {{ '$'.number_format($value['fees_cents'] / 100, 2) }}</li>
            @if (($monthTextCents ?? 0) > 0)
                <li>Text charges so far this month: {{ '$'.number_format($monthTextCents / 100, 2) }}</li>
            @endif
        </ul>
        @if ($valueAllTime['verified_visits'] > $value['verified_visits'])
            @if ($business->hasTypicalSpend())
                <p class="mt-2 text-base">All time: {{ $valueAllTime['verified_visits'] }} visits counted, about {{ '$'.number_format($valueAllTime['estimated_sales_cents'] / 100, 2) }} in estimated sales, based on your typical spend per visit (estimate)</p>
            @else
                <p class="mt-2 text-base">All time: {{ $valueAllTime['verified_visits'] }} visits counted</p>
            @endif
        @endif
    @else
        <p class="type-body-sm mt-1 text-text-2">After your first visit counts, this panel shows what Easy Refer brought you, in visits and in estimated sales.</p>
    @endif
    @include('partials.billing-forecast', ['class' => 'mt-2 text-text-1', 'preview' => $forecastPreview])
    @if ($business->hasTypicalSpend())
        <p class="mt-2 text-text-2">Estimates use your typical spend per visit of {{ '$'.number_format((int) $business->average_ticket_cents / 100, 2) }}. Update it any time in <a class="font-semibold text-link hover:underline" href="{{ route('settings.value') }}">Settings</a>.</p>
    @else
        <p class="mt-2 text-text-2">Estimates need your typical spend per visit. Add it any time in <a class="font-semibold text-link hover:underline" href="{{ route('settings.value') }}">Settings</a>, and this panel will estimate the sales your visits brought in.</p>
    @endif
    <p class="mt-2 text-text-2"><a class="font-semibold text-link hover:underline" href="{{ route('settings.billing') }}">Billing details</a></p>
</div>
