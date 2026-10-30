{{-- The DEMO device, layer 1 (Amendment 63, Section 9): the
     sticky amber banner. It rides every demo screen, including
     every error and timeout state, so a screenshot of any demo
     screen reads as a demo with no context. The persistent exit
     rides S1 to S5 only; its step is passed by the screen. --}}
<div class="demo-banner" role="note">
    <span class="demo-chip-solid">DEMO</span>
    <span class="demo-banner-text">{{ $business->name ?? 'Juniper Café' }} is a made-up shop</span>
    @if (($exitStep ?? null) !== null)
        <form method="POST" action="{{ route('demo.exit') }}" class="demo-banner-exit">
            @csrf
            <input type="hidden" name="from" value="banner">
            <button type="submit" class="text-action">Like it? Make it yours →</button>
        </form>
    @endif
</div>
