{{--
    W1 (Amendment 38, Train A3-8, SW2-F-22): the action-adjacent
    print warning. Screen chrome CAN branch where paper cannot:
    while the flow is inactive this line renders directly with
    the print controls, naming the consequence of printing now.
    It is NOT a block (print-ahead is a real activation
    workflow) and NOT a watermark (a stamp would go false the
    moment the flow activates). The including surface owns the
    conditional (@unless flowIsActive) and the placement; this
    partial owns the sentence, beside the qr-inactive-notice
    that names the missing step.
--}}
@if (($business ?? null) !== null && $business->flowBlockReason() === 'allowance_exhausted')
    {{-- ACT-QR-03 (activation redesign, census C-15): in the
         allowance cause the scan page is the new paused page,
         not a not-active page. --}}
    <p class="type-body mt-3 text-warning-text">Your code is paused right now. You can print, but anyone who scans this poster will see a paused page until you add a card.</p>
@else
    <p class="type-body mt-3 text-warning-text">Your program is not on right now. You can print, but anyone who scans this poster will see a not-active page until you turn it on.</p>
@endif
