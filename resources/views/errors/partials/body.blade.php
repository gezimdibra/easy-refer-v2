{{--
    Shared error page body (gap pack 06, Variant A). One skeleton in
    two contexts: the app context (ERR-3) rides the app layout's own
    padded main, the public context keeps the marketing section.
    Per-code data arrives from the code's own view: $errorCode,
    $errorIcon, $errorTint, $errorHeading, $errorLede.
    ERR-1: a quiet icon well per code (neutral tint 403/404, amber
    419/429, error tint 500); the icon is paired with the heading
    text, never the sole signal. ERR-2: honest per-code actions.
--}}
@php
    $errorApp = \App\Support\ErrorPageContext::isApp();
    $errorTintClasses = [
        'neutral' => 'bg-surface-2 text-text-2',
        'amber' => 'bg-warning-fill text-warning-text',
        'error' => 'bg-error-fill text-error-text',
    ][$errorTint];
    $errorOverview = route('dashboard');
    $errorHome = route('home');
    // The referring page where one exists (419's form, a stale
    // link's source); the context home is the fallback.
    $errorBack = url()->previous($errorApp ? $errorOverview : $errorHome);
@endphp
@if ($errorApp)
    <div class="mx-auto max-w-[640px] py-6 text-center">
@else
    <section class="shell section-pad">
        <div class="mx-auto max-w-[640px] text-center">
@endif
            <div class="error-icon-well mx-auto flex h-16 w-16 items-center justify-center rounded-2xl {{ $errorTintClasses }}" data-error-icon="{{ $errorIcon }}" data-error-tint="{{ $errorTint }}" aria-hidden="true">
                <x-icon name="{{ $errorIcon }}" :size="28" />
            </div>
            <p class="type-eyebrow mt-6 text-link">Error {{ $errorCode }}</p>
            <h1 class="type-display-sm mt-3 text-text-1">{{ $errorHeading }}</h1>
            <p class="type-lede mt-4 text-text-2">{{ $errorLede }}</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                @if ($errorCode === 419)
                    <a class="btn btn-primary" href="{{ $errorBack }}">Go back and try again</a>
                @elseif ($errorCode === 429)
                    <a class="btn btn-primary" href="{{ request()->fullUrl() }}">Try again</a>
                @elseif ($errorApp)
                    <a class="btn btn-primary" href="{{ $errorOverview }}">Back to Overview</a>
                @else
                    <a class="btn btn-primary" href="{{ $errorHome }}">Go to the home page</a>
                @endif

                @if ($errorApp)
                    @if ($errorCode === 429)
                        <a class="btn btn-secondary" href="{{ $errorOverview }}">Back to Overview</a>
                    @else
                        <a class="btn btn-secondary" href="{{ $errorOverview }}" onclick="if (window.history.length > 1) { window.history.back(); return false; }">Go back</a>
                    @endif
                @elseif ($errorCode === 429)
                    <a class="btn btn-secondary" href="{{ $errorHome }}">Go to the home page</a>
                @elseif (! auth()->check())
                    <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
                @endif
            </div>
            @if ($errorCode === 403)
                <p class="type-caption mt-6 text-text-2">If you think you should have access, contact us at <a class="text-text-2 underline decoration-line-strong underline-offset-[3px] hover:text-text-1" href="mailto:support@easy-refer.com">support@easy-refer.com</a>.</p>
            @endif
            @if ($errorCode === 500)
                <p class="type-caption mt-6 text-text-2"><a class="text-text-2 underline decoration-line-strong underline-offset-[3px] hover:text-text-1" href="mailto:support@easy-refer.com">support@easy-refer.com</a></p>
            @endif
        </div>
@if (! $errorApp)
    </section>
@endif
