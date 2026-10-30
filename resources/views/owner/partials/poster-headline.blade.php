{{--
    [REF-POSTER-04] (Amendment 58): the ONE print headline,
    rendered by every sheet (flowing, tent, receipt strip)
    and by the receipt-card partial, so the four artifacts
    can never carry different headline wording. The text is
    Business::posterHeadline() exactly (tags removed, the
    rendered text equals the composer output); the spans
    are presentation only — F5 coloring puts brand-600 on
    each reward's amount figure and nothing else. Expects
    the poster view's composition variables: $rewardText,
    $headlineSplit, $rewardAmount / $rewardRest and
    $referrerAmount / $referrerRest.
--}}@if ($rewardText === '')Share with a friend, you both get rewarded when they visit.@elseif ($headlineSplit)Give a friend <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }}. Get <span class="text-brand-600">{{ $referrerAmount }}</span>{{ $referrerRest }} when they visit.@else Share with a friend, you both get <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }} when they visit.@endif
