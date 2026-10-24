{{--
    The starter kit lead form (inventory LEAD-01, spec Section 2.7):
    a section on Home and the closing block of each vertical page.
    There is no standalone starter-kit route. Redesign
    (redesign-spec.md Section 10.1 item 10): two columns, text left
    and the form in a radius-xl card at 32px padding, with the
    email input and submit joined on one outline.
--}}
<section id="starter-kit" class="bg-surface-0">
    <div class="shell section-pad grid items-start gap-10 lg:grid-cols-2">
        <div>
            <h2 class="type-title-2 text-text-1">Get the free Referral Program Starter Kit</h2>
            <p class="type-body mt-3 max-w-[68ch] text-text-2">We will email the kit and a short follow-up series. Unsubscribe any time.</p>
        </div>
        <div class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
            @if (session('lead_status'))
                <p class="banner banner-success" role="status">{{ session('lead_status') }}</p>
            @else
                <form method="POST" action="{{ route('leads.starter-kit') }}">
                    @csrf
                    <input type="hidden" name="source" value="{{ $source ?? 'home' }}">

                    <label class="form-label" for="lead-email-{{ $source ?? 'home' }}">Email address</label>
                    <div class="joined">
                        <input class="form-input" id="lead-email-{{ $source ?? 'home' }}" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required
                            @if ($errors->has('email')) aria-invalid="true" aria-describedby="lead-email-error" @endif>
                        <button type="submit" class="btn btn-secondary">Send me the kit</button>
                    </div>
                    @error('email')
                        <p class="form-error" id="lead-email-error">{{ $message }}</p>
                    @enderror
                    @if (config('services.turnstile.site_key'))
                        <div class="cf-turnstile mt-3" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    @endif
                </form>
            @endif
        </div>
    </div>
</section>
