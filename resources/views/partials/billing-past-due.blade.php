{{--
    The failed-payment block (SV-03; copy Amendment 22
    BILL-PD-01, amended by Amendment 35 SA-01): the sentence,
    the fix, and the time line (the real grace-end date while
    the window runs; the paused truth once it has closed), in
    the same words on the billing page, the dashboard gate,
    and (through App\Support\BillingCopy) the Action Center
    row. $cardHref is the one surface difference: the billing
    page anchors to its own card form; every other surface
    links to the billing page's card anchor. Both including
    surfaces pass their $business in scope.
--}}
<p class="font-semibold text-text-1">{{ \App\Support\BillingCopy::pastDueLine() }}</p>
<p class="mt-3"><a class="btn btn-primary" href="{{ $cardHref }}">Update card</a></p>
<p class="type-body-sm mt-3 text-text-2">{{ \App\Support\BillingCopy::statusLine($business) }}</p>
