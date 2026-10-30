{{--
    The share page's share mechanics, shared verbatim with the
    RS-N2 receipt prime (Train B): one primary Share action (the
    native share sheet where the browser offers one, the
    clipboard everywhere else) and the copyable link field.
    Extracted so the receipt and the share page can never drift
    in behaviour (design pass Section 3.5: reuse the share
    page's own mechanics).
    Expects: $shareUrl, $shareMessage, $business, and optionally
    $fieldLabel (default "Your referral link").
--}}
<div x-data="{ copied: false }">
    <div class="mt-5">
        <button type="button" class="btn btn-primary btn-flow w-full"
            @click="if (navigator.share) { navigator.share({ title: {{ Js::from($business->name) }}, text: {{ Js::from($shareMessage) }}, url: {{ Js::from($shareUrl) }} }) } else { navigator.clipboard.writeText($refs.shareUrl.value); copied = true }">Share your link</button>
    </div>

    <div class="field mt-4">
        <label class="form-label" for="share_url{{ isset($fieldId) ? '_'.$fieldId : '' }}">{{ $fieldLabel ?? 'Your referral link' }}</label>
        <div class="joined">
            <input class="form-input" id="share_url{{ isset($fieldId) ? '_'.$fieldId : '' }}" type="text" readonly value="{{ $shareUrl }}" x-ref="shareUrl">
            <button type="button" class="btn btn-primary"
                @click="navigator.clipboard.writeText($refs.shareUrl.value); copied = true"
                x-text="copied ? 'Copied' : 'Copy link'">Copy link</button>
        </div>
    </div>
</div>
