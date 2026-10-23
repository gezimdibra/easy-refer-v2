{{--
    The starter kit lead form (inventory LEAD-01, spec Section 2.7):
    a section on Home and the closing block of each vertical page.
    There is no standalone starter-kit route.
--}}
<section id="starter-kit" class="mx-auto max-w-[720px] px-5 py-12">
    <div class="card">
        <h2 class="text-2xl font-bold text-text-1">Get the free Referral Program Starter Kit</h2>

        @if (session('lead_status'))
            <p class="banner banner-success mt-4" role="status">{{ session('lead_status') }}</p>
        @else
            <form method="POST" action="{{ route('leads.starter-kit') }}" class="mt-4">
                @csrf
                <input type="hidden" name="source" value="{{ $source ?? 'home' }}">

                <label class="form-label" for="lead-email-{{ $source ?? 'home' }}">Email address</label>
                <div class="flex gap-2">
                    <input class="form-input" id="lead-email-{{ $source ?? 'home' }}" name="email" type="email" value="{{ old('email') }}" required
                        @if ($errors->has('email')) aria-invalid="true" aria-describedby="lead-email-error" @endif>
                    <button type="submit" class="btn btn-secondary shrink-0">Send me the kit</button>
                </div>
                @error('email')
                    <p class="form-error" id="lead-email-error">{{ $message }}</p>
                @enderror
                <p class="form-help">We will email the kit and a short follow-up series. Unsubscribe any time.</p>

                @if (config('services.turnstile.site_key'))
                    <div class="cf-turnstile mt-3" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                @endif
            </form>
        @endif
    </div>
</section>
