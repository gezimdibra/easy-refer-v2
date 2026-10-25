{{-- The Turnstile widget (spec Sections 10.4 and 3 Step 3):
     rendered only when a site key is configured; when keys are
     absent the server-side check passes by design and the flow
     still works (staging posture, spec Section 10.5). --}}
@if (config('services.turnstile.site_key'))
    <div class="field">
        <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="auto"></div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </div>
@endif
