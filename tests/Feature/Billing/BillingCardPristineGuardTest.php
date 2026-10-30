<?php

namespace Tests\Feature\Billing;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-CARD-01 (fresh-eyes fix wave, 2026-10-25): a tester
 * playing a brand-new owner clicked the button labeled
 * "Add card" on the untouched billing form and was greeted
 * with the full all-fields-invalid state (red border,
 * Square's "Enter a valid card number.", and the BILL-13
 * summary listing all three fields as not valid) before
 * typing a single character. The mechanism (pinned by the
 * read-only verification lane): nothing errors on load; the
 * button IS the submit, and the handler tokenized the empty
 * form, so Square returned one error per empty field.
 *
 * The fix is a pristine-submit guard in the shared partial:
 * the card component's own input events report each field's
 * content state (CardInputEvent detail.currentState.isEmpty),
 * and while every field is still empty a submit does NOT
 * tokenize; it focuses the card field and shows the gentle
 * hint "Enter your card details first." in the summary
 * region instead. The first real input clears the hint.
 * Once any field has content, behavior is the r18-proven
 * path, byte for byte.
 *
 * These pins read the rendered billing page (the partial's
 * script is inline) in the OTP-01 static-pin pattern: if the
 * guard is removed or reordered after the tokenize call, the
 * suite says so even where the live SDK cannot run (the SDK
 * needs HTTPS and real sandbox credentials, so the browser
 * leg is a Gate 3 real-Chrome check at release time).
 */
class BillingCardPristineGuardTest extends TestCase
{
    use RefreshDatabase;

    private function noCardBillingHtml(): string
    {
        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        return $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();
    }

    public function test_the_pristine_submit_guard_ships_wired_before_the_tokenize_call(): void
    {
        $html = $this->noCardBillingHtml();

        // The guard and its hint ship with the form.
        $this->assertStringContainsString('FE-CARD-01', $html);
        $this->assertStringContainsString('data-billing-card-hint', $html);
        $this->assertStringContainsString('Enter your card details first.', $html);

        // Content is keyed on the SDK's own field-state
        // reports, never on focus alone: the tracker reads
        // currentState.isEmpty off the card input events.
        $this->assertStringContainsString('currentState.isEmpty', $html);
        $this->assertStringContainsString('card.addEventListener(', $html);
        $this->assertStringContainsString("'cardBrandChanged'", $html);
        $this->assertStringContainsString("'postalCodeChanged'", $html);

        // The guard runs before the busy state engages and
        // before tokenize is reached: a pristine submit can
        // produce no Square error state at all.
        $guardAt = strpos($html, 'if (!cardHasContent())');
        $this->assertNotFalse($guardAt, 'The pristine-submit guard is missing from the card form script.');
        $tokenizingAt = strpos($html, "form.dataset.tokenizing = 'true'");
        $this->assertNotFalse($tokenizingAt);
        $this->assertLessThan($tokenizingAt, $guardAt, 'The pristine guard must run before the form marks itself tokenizing.');
        $tokenizeAt = strpos($html, 'card.tokenize()');
        $this->assertNotFalse($tokenizeAt);
        $this->assertLessThan($tokenizeAt, $guardAt, 'The pristine guard must run before card.tokenize() is called.');
    }

    public function test_the_hint_is_announced_and_cleared_and_the_error_path_is_untouched(): void
    {
        $html = $this->noCardBillingHtml();

        // The hint is announced through the polite live
        // region and cleared out of the way on the real
        // tokenize attempt.
        $this->assertStringContainsString("live.textContent = 'Enter your card details first.';", $html);
        $this->assertStringContainsString('hint.hidden = true', $html);
        $this->assertStringContainsString('data-billing-tokenize-heading', $html);

        // BILL-13 error path, byte-preserved: Square's
        // messages listed verbatim, the summary focused, the
        // outcome announced, the busy state managed by hand.
        $this->assertStringContainsString('Some card details need another look.', $html);
        $this->assertStringContainsString('(result.errors || []).forEach', $html);
        $this->assertStringContainsString('summary.focus()', $html);
        $this->assertStringContainsString("live.textContent = 'Some card details need another look.';", $html);
        $this->assertStringContainsString("form.dataset.tokenizing = 'true'", $html);
        $this->assertStringContainsString('setBusy(true)', $html);
        $this->assertStringContainsString('data-billing-live', $html);

        // BILL-02 (SDK load failure) still ships hidden with
        // its copy, and the r18 style contract still holds:
        // element selectors only, no font family.
        $this->assertStringContainsString('data-billing-sdk-error', $html);
        $this->assertStringContainsString('The secure card form could not load. Check your connection and try again. Your other billing details are up to date.', $html);
        $this->assertStringContainsString("'.input-container':", $html);
        $this->assertStringContainsString("'input':", $html);
        $this->assertStringContainsString("'input::placeholder':", $html);
        $this->assertStringContainsString("'.message-text':", $html);
        $this->assertStringNotContainsString("'.input':", $html);
        $this->assertStringNotContainsString('fontFamily', $html);
    }

    public function test_the_replace_mount_ships_the_same_guard(): void
    {
        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
        $business = World::business();
        $owner = World::owner($business);
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_test1',
        ]);

        $html = $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();

        // One partial, both mounts: the Replace form carries
        // the same pristine guard and hint as the Add form.
        $this->assertStringContainsString('id="card-form-replace"', $html);
        $this->assertStringContainsString('FE-CARD-01', $html);
        $this->assertStringContainsString('Enter your card details first.', $html);
        $this->assertStringContainsString('if (!cardHasContent())', $html);
    }
}
