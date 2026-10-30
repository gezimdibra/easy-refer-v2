{{--
    The forecast line (RK-03; copy Amendment 22 BILL-FC-01):
    rendered on the billing page and the dashboard Value panel
    from the one BillingForecast helper over the one settlement
    preview, so both surfaces state the same expected charge.
    Renders nothing when nothing has accrued.
--}}
@php($forecastLine = isset($preview) ? \App\Support\BillingForecast::lineFrom($preview, now(), $business ?? null) : \App\Support\BillingForecast::line($business))
@if ($forecastLine !== null)
    <p class="{{ $class ?? 'mt-3 text-text-1' }}">{{ $forecastLine }}</p>
@endif
