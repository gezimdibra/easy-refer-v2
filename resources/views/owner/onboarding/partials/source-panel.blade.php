{{--
    SK-6 [SRC-01] (Amendment 67): the optional "how did you
    hear about us" panel. ONE partial included by the finish
    screen below the value panel, rendered only while no
    signup source is recorded and the business did not claim
    its page (a claimed business's claim_source is the
    authoritative channel; the panel is never offered to one).
    Its own small form, never folded into the
    onboarding.complete door forms, so Skip is doing nothing
    and pressing a door. The answer is stored once at the
    business level; it is read by no charge, reward, cap,
    activation, or ranking path, only by the growth readout
    in the admin console.
--}}
<div class="panel mt-8">
    <h2 class="type-title-3 text-text-1">How did you hear about us?</h2>
    <p class="type-body mt-2 text-text-2">Optional. It helps us know what is working, so we can do more of it. You can skip this.</p>

    <form method="POST" action="{{ route('onboarding.source') }}" class="mt-4" data-loading>
        @csrf
        <label for="signup_source">Pick the closest one</label>
        <select id="signup_source" name="signup_source"
            aria-invalid="{{ $errors->has('signup_source') ? 'true' : 'false' }}"
            @if ($errors->has('signup_source')) aria-describedby="signup_source-error" @endif>
            <option value="">Skip this question</option>
            @foreach (($sourceOptions ?? []) as $key => $label)
                <option value="{{ $key }}" @selected(old('signup_source') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        @error('signup_source') <p class="form-error" id="signup_source-error">{{ $message }}</p> @enderror

        <div class="mt-4">
            <label for="signup_source_detail">Street or area (optional)</label>
            <input id="signup_source_detail" name="signup_source_detail" type="text" maxlength="120"
                value="{{ old('signup_source_detail') }}"
                placeholder="Like Bay Street"
                aria-invalid="{{ $errors->has('signup_source_detail') ? 'true' : 'false' }}"
                @if ($errors->has('signup_source_detail')) aria-describedby="signup_source_detail-error signup_source_detail-help" @else aria-describedby="signup_source_detail-help" @endif>
            @error('signup_source_detail') <p class="form-error" id="signup_source_detail-error">{{ $message }}</p> @enderror
            <p class="form-help" id="signup_source_detail-help">If a sign, a poster, or a visit brought you here, the street helps us see which streets are working.</p>
        </div>
        <p class="mt-4"><button type="submit" class="btn btn-secondary">Save</button></p>
    </form>
</div>
