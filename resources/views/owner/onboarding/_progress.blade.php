{{--
    The flow's progress (design pack Section 4.0 + benchmark
    O-2): the signup wizard's segmented bar, the position in
    words ("Step N of 6"), and a labeled stepper naming the six
    steps, ticked truthfully from the live states. A segment is
    filled only when its step is actually done, so progress
    never claims a skipped step (pack Section 5.1).
    Expects: $states (step => bool), $current (step slug).
--}}
@php
    $position = \App\Support\Onboarding::position($current);
    $labels = \App\Support\Onboarding::LABELS;
@endphp
<div class="mb-8">
    <div class="flex gap-1.5" aria-hidden="true">
        @foreach (\App\Support\Onboarding::STEPS as $step)
            <span class="segment {{ ($states[$step] ?? false) ? 'segment-on' : '' }}"></span>
        @endforeach
    </div>
    <p class="mt-3 flex items-baseline gap-2">
        <span class="type-caption font-semibold text-text-1">Step {{ $position }} of {{ count(\App\Support\Onboarding::STEPS) }}</span>
        <span class="type-caption text-text-2">{{ $labels[$current] }}</span>
    </p>
    <p class="type-caption mt-2 text-text-2">
        @foreach (\App\Support\Onboarding::STEPS as $step)
            <span class="{{ $step === $current ? 'font-semibold text-text-1' : '' }}">{{ ($states[$step] ?? false) ? '✓ ' : '' }}{{ $labels[$step] }}</span>@unless ($loop->last) <span aria-hidden="true"> · </span> @endunless
        @endforeach
    </p>
</div>
