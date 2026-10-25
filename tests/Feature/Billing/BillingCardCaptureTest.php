<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Auth\StepUpService;
use App\Services\Billing\SquareOutcomeUnknown;
use App\Services\Square\NullSquareCustomerGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Card capture on the billing page (billing UI spec Sections 2
 * Block C and 3, build unit BU-4): the Web Payments SDK tokenizes
 * in the browser, the page POSTs only the single-use source id,
 * and the endpoint exchanges it through CardOnFileService. The
 * source id is never stored or logged. Replace and remove sit
 * behind the Section 8.7 step-up; add does not.
 */
class BillingCardCaptureTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);
    }

    private function card(Business $business, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'business_id' => $business->id,
            'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_test1',
        ], $overrides));
    }

    public function test_no_card_state_renders_the_sdk_capture_form(): void
    {
        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertSee('id="card-form"', false);
        $response->assertSee('action="'.route('settings.billing.card.store').'"', false);
        $response->assertSee('name="source_id"', false);
        $response->assertSee('https://sandbox.web.squarecdn.com/v1/square.js', false);
        $response->assertSee('sq0idp-test-app', false);
        $response->assertSee('LOC_TEST', false);
        // BILL-02 (SDK load failure) and BILL-13 (tokenize summary)
        // copy ship with the form; the rest of the page works
        // without the SDK.
        $response->assertSee('The secure card form could not load. Check your connection and try again. Your other billing details are up to date.');
        $response->assertSee('Some card details need another look.');
        $response->assertSee('Add card');
    }

    public function test_capture_form_style_object_matches_the_sdk_validator(): void
    {
        // The CDN-served SDK validates the style object at attach
        // and rejects the whole form on any invalid entry (proven
        // live against SDK 1.87.1 on 2026-10-25, sqfix probes
        // 1-4): the class selectors '.input' and
        // '.input::placeholder' throw InvalidStylesError (the
        // element selectors 'input' / 'input::placeholder' are
        // the valid forms), and any named font family value,
        // including the computed stack, is rejected the same
        // way. The catch-all then showed BILL-02 with the error
        // swallowed, so the form never rendered in any browser.
        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertOk();
        $response->assertDontSee("'.input':", false);
        $response->assertDontSee("'.input::placeholder':", false);
        $response->assertSee("'input':", false);
        $response->assertSee("'input::placeholder':", false);
        $response->assertDontSee('fontFamily:', false);
        // A failed load must be diagnosable: the failure path
        // logs the caught error instead of swallowing it.
        $response->assertSee('console.error', false);
        $response->assertSee('loadFailed(error)', false);
    }

    public function test_production_environment_loads_the_production_sdk_host(): void
    {
        config(['services.square.environment' => 'production']);
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertSee('https://web.squarecdn.com/v1/square.js', false);
        $response->assertDontSee('sandbox.web.squarecdn.com', false);
    }

    public function test_billing_page_ships_no_csp_header_blocking_the_sdk(): void
    {
        // Spec Section 3 CSP checkpoint: the app ships no
        // Content-Security-Policy today, so nothing in our headers
        // blocks the Square CDN. If a CSP is ever added, this test
        // is the tripwire that forces the Square hosts onto its
        // allowlist (script-src web.squarecdn.com /
        // sandbox.web.squarecdn.com, frame-src + connect-src for
        // the SDK's hosted fields).
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_store_saves_the_card_activates_and_confirms_with_bill07(): void
    {
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('settings.billing.card.store'), [
            'source_id' => 'cnon:fake-source-id',
        ]);

        $response->assertRedirect(route('settings.billing'));
        $response->assertSessionHas('status', 'Card saved. It ends in 4242 and is now the card we charge.');

        $method = PaymentMethod::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $method->state);
        $this->assertTrue($method->is_default);
        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_added')->count());
        $this->assertCount(1, $this->gateway->saveCardCalls);

        // The single-use source id is stored nowhere and logged
        // nowhere: not on the row, not in the audit trail.
        $this->assertStringNotContainsString('cnon:fake-source-id', (string) json_encode($method->getAttributes()));
        $this->assertStringNotContainsString(
            'cnon:fake-source-id',
            (string) AuditLog::query()->pluck('metadata')->implode(' '),
        );
    }

    public function test_store_declined_card_shows_bill03_and_keeps_the_previous_default(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $existing = $this->card($business);
        $this->gateway->cardFailureCode = 'GENERIC_DECLINE';

        $response = $this->actingAs($owner)->post(route('settings.billing.card.store'), [
            'source_id' => 'cnon:declined',
        ]);

        $response->assertRedirect(route('settings.billing'));
        $response->assertSessionHasErrors([
            'card' => 'Your card was declined. No charge was made. Check the details or try a different card.',
        ]);

        $failed = PaymentMethod::query()
            ->where('business_id', $business->id)
            ->where('state', PaymentMethod::STATE_FAILED)
            ->firstOrFail();
        $this->assertSame('GENERIC_DECLINE', $failed->error_code);
        $this->assertFalse($failed->is_default);
        $this->assertTrue($existing->fresh()->is_default);
    }

    public function test_store_unknown_outcome_leaves_the_row_pending_and_the_page_shows_bill05(): void
    {
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);
        $this->gateway->saveCardException = new SquareOutcomeUnknown('timeout');

        $response = $this->actingAs($owner)->post(route('settings.billing.card.store'), [
            'source_id' => 'cnon:unknown',
        ]);

        $response->assertRedirect(route('settings.billing'));
        $response->assertSessionHasNoErrors();

        $method = PaymentMethod::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(PaymentMethod::STATE_PENDING, $method->state);

        $page = $this->actingAs($owner)->get(route('settings.billing'));
        $page->assertSee('We are still confirming your card with Square. This page will update on its own. Please do not add the card again.');
        $page->assertDontSee('name="source_id"', false);
    }

    public function test_store_without_a_provisionable_customer_shows_bill04_and_writes_no_card(): void
    {
        $this->app->instance(SquareCustomerGateway::class, new NullSquareCustomerGateway);
        $business = World::business(['billing_status' => 'none']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('settings.billing.card.store'), [
            'source_id' => 'cnon:no-customer',
        ]);

        $response->assertSessionHasErrors([
            'card' => 'We could not set up billing for your business. Try again, and contact support@easy-refer.com if it keeps happening.',
        ]);
        $this->assertSame(0, PaymentMethod::query()->where('business_id', $business->id)->count());
    }

    public function test_guests_cannot_post_a_card(): void
    {
        $this->post(route('settings.billing.card.store'), ['source_id' => 'cnon:x'])
            ->assertRedirect(route('login'));
    }

    public function test_card_state_renders_replace_and_remove_behind_a_confirmation(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->card($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'));

        $response->assertSee('Replace card');
        $response->assertSee('Remove card');
        $response->assertSee('Remove this card? Visits keep counting and rewards keep working. Charges pause until you add a card, and the amount you owe stays on your account.');
        $response->assertSee('action="'.route('settings.billing.card.replace').'"', false);
        $response->assertSee('action="'.route('settings.billing.card.remove').'"', false);
    }

    public function test_replace_requires_a_fresh_step_up(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->card($business);

        $this->actingAs($owner)->post(route('settings.billing.card.replace'), [
            'source_id' => 'cnon:replacement',
        ])->assertRedirect(route('security.step-up'));

        $this->assertCount(0, $this->gateway->saveCardCalls);
    }

    public function test_replace_with_step_up_demotes_the_old_default_and_audits(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $existing = $this->card($business);

        $response = $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.replace'), ['source_id' => 'cnon:replacement']);

        $response->assertRedirect(route('settings.billing'));
        $response->assertSessionHas('status', 'Card saved. It ends in 4242 and is now the card we charge.');

        $this->assertFalse($existing->fresh()->is_default);
        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $existing->fresh()->state);
        $new = PaymentMethod::defaultFor($business->fresh());
        $this->assertNotNull($new);
        $this->assertSame('4242', $new->last4);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_added')->count());
    }

    public function test_remove_requires_a_fresh_step_up(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $card = $this->card($business);

        $this->actingAs($owner)->post(route('settings.billing.card.remove'))
            ->assertRedirect(route('security.step-up'));

        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $card->fresh()->state);
    }

    public function test_remove_card_interrupted_by_step_up_returns_to_billing_and_completes(): void
    {
        // T3-F3: the challenge must hand the owner back to the
        // page the change lives on, with the change still to
        // complete there, never strand them on the Security page.
        $business = World::business();
        $owner = World::owner($business);
        $card = $this->card($business);

        $this->actingAs($owner)->from(route('settings.billing'))
            ->post(route('settings.billing.card.remove'))
            ->assertRedirect(route('security.step-up'));

        // The interrupted POST changed nothing.
        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $card->fresh()->state);

        $this->actingAs($owner)
            ->post(route('security.step-up.store'), ['password' => 'WorldPassword01!'])
            ->assertRedirect(route('settings.billing'));

        // Back on Billing inside the fresh window, the same press
        // completes the removal with no second challenge.
        $this->actingAs($owner)
            ->post(route('settings.billing.card.remove'))
            ->assertRedirect(route('settings.billing'));

        $this->assertSame(PaymentMethod::STATE_REMOVED, $card->fresh()->state);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_removed')->count());
    }

    public function test_remove_with_step_up_removes_promotes_and_shows_bill09(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $default = $this->card($business);
        $spare = $this->card($business, [
            'square_card_id' => 'card_test_2',
            'last4' => '1111',
            'is_default' => false,
            'idempotency_key' => 'card_'.$business->id.'_test2',
        ]);

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.remove'))
            ->assertRedirect(route('settings.billing'));

        $this->assertSame(PaymentMethod::STATE_REMOVED, $default->fresh()->state);
        $this->assertFalse($default->fresh()->is_default);
        $this->assertTrue($spare->fresh()->is_default);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_removed')->count());

        // Removing the last card leaves the BILL-09 state: active
        // business, no card, charges paused.
        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.remove'))
            ->assertRedirect(route('settings.billing'));

        $page = $this->actingAs($owner)->get(route('settings.billing'));
        $page->assertSee('No card on file. Visits keep counting, and the amount builds up here. Add a card to restart monthly charges.');
    }
}
