{{--
    The starter kit form itself (inventory LEAD-01): the status
    line or the email capture, shared by the standalone section
    and the home closing band's merged block (copy release,
    O-3), so the capture exists exactly once in the codebase.
    Expects $source.
--}}
@if (session('lead_status'))
    <p class="banner banner-success" role="status">{{ session('lead_status') }}</p>
@else
    <form method="POST" action="{{ route('leads.starter-kit') }}" data-loading>
        @csrf
        <input type="hidden" name="source" value="{{ $source ?? 'home' }}">

        <label class="form-label" for="lead-email-{{ $source ?? 'home' }}">Email address</label>
        <div class="joined">
            <input class="form-input" id="lead-email-{{ $source ?? 'home' }}" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required
                @if ($errors->has('email')) aria-invalid="true" aria-describedby="lead-email-error" @endif>
            <button type="submit" class="btn btn-secondary">Join the list</button>
        </div>
        @error('email')
            <p class="form-error" id="lead-email-error">{{ $message }}</p>
        @enderror
        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="auto"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif
    </form>
@endif
