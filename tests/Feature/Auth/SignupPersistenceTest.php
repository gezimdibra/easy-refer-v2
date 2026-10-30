<?php

namespace Tests\Feature\Auth;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignSender;
use App\Support\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS2-2 signup persistence (value-train design pass Part 3,
 * Variant A + the OR-1 amendment in one-reward-design-pass.md
 * Section 5.3): the account is created at the register
 * wizard's Step 3 identity checkpoint; the address lives in
 * onboarding as the Location step; an abandoned signup resumes
 * where it stopped, on sign-in, with its answers intact.
 *
 * The lifecycle states S0-S7 of the design pass are pinned by
 * name in the test comments.
 */
class SignupPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquareCustomerGateway $squareCustomers;

    private const PASSWORD = 'a long phrase nobody else uses 42';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Notification::fake();

        $this->squareCustomers = new FakeSquareCustomerGateway;
        $this->app->instance(SquareCustomerGateway::class, $this->squareCustomers);
        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);

        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function checkpointPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Corner Deli',
            'business_type' => 'cafe',
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    /** Submit the checkpoint; returns the persisted business. */
    private function checkpoint(array $overrides = []): Business
    {
        $this->post(route('register.store'), $this->checkpointPayload($overrides))
            ->assertRedirect(route('onboarding.welcome'));
        $this->assertAuthenticated();

        return Business::query()->where('name', $overrides['business_name'] ?? 'Corner Deli')->firstOrFail();
    }

    /** Leave (sign out), then return the way an owner does: the sign-in form. */
    private function abandonAndReturn(string $email = 'dana@example.test'): void
    {
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'identifier' => $email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    /** The dashboard resume banner's target for the signed-in owner. */
    private function assertResumesAt(Business $business, string $step): void
    {
        $page = $this->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('Pick up where you left off');
        $page->assertSee(route('onboarding.step', $step), false);

        $owner = $business->fresh()->owner;
        $this->assertSame($step, Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner)));
    }

    // S1 + billing absence: at the checkpoint the account exists
    // and NOTHING else does: no address, no Square object, no
    // card, no charge object, no active flow.
    public function test_checkpoint_persists_the_account_and_nothing_else(): void
    {
        $business = $this->checkpoint();

        $this->assertNull($business->street);
        $this->assertNull($business->city);
        $this->assertNull($business->state);
        $this->assertNull($business->postal_code);
        $this->assertNull($business->country);

        // Billing absence, proven by absence: no Square call was
        // made, no customer id, no payment method, no billing
        // event, billing untouched.
        $this->assertSame([], $this->squareCustomers->createdForBusinessIds);
        $this->assertNull($business->square_customer_id);
        $this->assertSame(0, PaymentMethod::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());
        $this->assertSame('none', $business->billing_status);
        $this->assertFalse($business->flowIsActive());

        // What DOES exist: the owner (login-able, unverified),
        // the launch QR code, and the free-visit credit.
        $owner = $business->owner;
        $this->assertNotNull($owner);
        $this->assertNull($owner->email_verified_at);
        $this->assertDatabaseHas('qr_codes', ['business_id' => $business->id]);
        $this->assertSame(5, $business->free_visits_remaining);
        $this->assertNull($business->onboarding_completed_at);
    }

    // S0: before the checkpoint, nothing persists at all.
    public function test_nothing_persists_before_the_checkpoint(): void
    {
        $this->get(route('register'))->assertOk();

        $this->assertSame(0, Business::count());
        $this->assertSame(0, User::count());
    }

    // Turnstile degraded form: with no keys configured (the
    // production state today), the widget does not render and
    // the checkpoint verifies on the existing protections only.
    public function test_turnstile_stays_degraded_when_no_keys_are_configured(): void
    {
        $this->assertEmpty(config('services.turnstile.site_key'));
        $this->assertEmpty(config('services.turnstile.secret_key'));

        $this->get(route('register'))->assertOk()->assertDontSee('cf-turnstile');

        $business = $this->checkpoint();
        $this->assertNotNull($business->owner);
    }

    // The headline acceptance: abandon at EACH step boundary;
    // the resumed state equals the never-left state, answers
    // intact and editable, step by step (S3 through the finish).
    public function test_abandon_at_each_step_boundary_resumes_where_it_stopped(): void
    {
        $business = $this->checkpoint();

        // Boundary 0: right after the checkpoint (S3). Resume at
        // Rewards; the signup answers are intact (the cafe
        // starter for the type given at signup is prefilled).
        $this->abandonAndReturn();
        $this->assertResumesAt($business, 'rewards');
        $this->get(route('onboarding.step', 'rewards'))
            ->assertOk()
            ->assertSee('A free regular coffee');

        // Do Rewards (the ONE-reward step, OR-1: one pick).
        $this->post(route('owner.reward-texts'), [
            'friend_reward_text' => 'A free regular coffee',
            'advance' => '1',
            'presented_reward_text' => 'A free regular coffee',
        ])->assertRedirect();

        // Boundary 1: resume at Card; the reward is intact.
        $this->abandonAndReturn();
        $this->assertResumesAt($business, 'card');
        $this->get(route('onboarding.step', 'rewards'))
            ->assertSee('Reward set.')
            ->assertSee('A free regular coffee');

        // Do Card (the card moment: Square is provisioned HERE,
        // exactly once, and a card now exists; still no charge).
        $this->post(route('onboarding.card.store'), ['source_id' => 'cnon:ok'])
            ->assertRedirect(route('onboarding.step', 'card'));
        $this->assertSame([$business->id], $this->squareCustomers->createdForBusinessIds);
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());

        // Boundary 2: resume at Location; the card is intact.
        $this->abandonAndReturn();
        $this->assertResumesAt($business, 'location');
        $this->get(route('onboarding.step', 'card'))->assertSee('Card on file.');

        // Do Location.
        $this->post(route('onboarding.location.store'), $this->addressPayload())
            ->assertRedirect(route('onboarding.step', 'verify'));
        // Provisioning already happened at the card moment: the
        // Location save creates no second Square customer.
        $this->assertSame([$business->id], $this->squareCustomers->createdForBusinessIds);

        // Boundary 3: resume at Verify; the address is intact
        // and shown back on its step.
        $this->abandonAndReturn();
        $this->assertResumesAt($business, 'verify');
        $this->get(route('onboarding.step', 'location'))
            ->assertSee('Address saved.')
            ->assertSee('1 Main Street, New York');

        // Do Verify through the signed link.
        $owner = $business->owner;
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $owner->id,
            'hash' => sha1($owner->getEmailForVerification()),
        ]);
        $this->get($url)->assertRedirect(route('onboarding.step', 'verify'));

        // Boundary 4: resume at Poster.
        $this->abandonAndReturn();
        $this->assertResumesAt($business, 'poster');

        // Do Poster (print the counter sheet).
        $this->get(route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]))->assertOk();

        // Boundary 5: resume at Try it.
        $this->abandonAndReturn();
        $this->assertResumesAt($business, 'test');

        // Do Try it (the owner's own join).
        $owner = $business->fresh()->owner;
        $owner->forceFill(['phone_e164' => '+15557650109'])->save();
        World::membership($business->fresh(), World::customer('+15557650109'));

        // Boundary 6: nothing is open; the welcome door goes
        // straight to the finish screen, exactly as if the owner
        // had never left.
        $this->abandonAndReturn();
        $this->get(route('onboarding.welcome'))->assertRedirect(route('onboarding.finish'));
        $this->assertNull(Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $business->fresh()->owner)));
    }

    // S2 + the task's duplicate rule: a second registration
    // against a persisted half-account creates NO second
    // account and hits NO dead wall: the generic message names
    // the sign-in path, and signing in IS the resume.
    public function test_duplicate_registration_against_a_half_account_resolves_to_resume(): void
    {
        $business = $this->checkpoint();
        $this->post(route('logout'));

        // Same email, new phone.
        $this->post(route('register.store'), $this->checkpointPayload([
            'phone' => '(718) 555-0199',
        ]))->assertSessionHasErrors([
            'account' => 'An account with these details already exists. Sign in or reset your password.',
        ]);
        $this->assertGuest();
        $this->assertSame(1, Business::count());
        $this->assertSame(1, User::count());

        // Same phone, new email.
        $this->post(route('register.store'), $this->checkpointPayload([
            'email' => 'other@example.test',
        ]))->assertSessionHasErrors('account');
        $this->assertSame(1, Business::count());
        $this->assertSame(1, User::count());

        // The original account is untouched, and its credentials
        // resume it at its first open step.
        $this->assertSame('Corner Deli', $business->fresh()->name);
        $this->post(route('login.store'), [
            'identifier' => 'dana@example.test',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->assertResumesAt($business, 'rewards');
    }

    // S6 + absence proofs: a half-account (and even a live,
    // addressless one) cannot be discovered through the flow,
    // claimed through a join, or campaigned to.
    public function test_a_half_account_cannot_be_discovered_claimed_or_campaigned_to(): void
    {
        $business = $this->checkpoint();

        // Not discoverable/claimable: its code's landing is the
        // designed not-active answer, and enrollment is refused.
        [, $qrToken] = World::qr($business);
        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertNotFound()
            ->assertSee('This code is not active');
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Sam',
            'last_name' => 'Reed',
            'phone' => '(212) 555-0177',
            'consent' => '1',
        ])->assertNotFound();
        $this->assertSame(0, Membership::query()->where('business_id', $business->id)->count());

        // Not campaignable: it has no audience (no memberships),
        // and a segment draft send is blocked by the flow gate
        // with zero recipients created.
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'June sale',
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $business->owner->id,
            'audience_segment' => SmsCampaign::SEGMENT_LAPSED_REFERRERS,
        ]);
        $result = app(CampaignSender::class)->send($campaign);
        $this->assertSame('blocked_flow', $result['outcome']);
        $this->assertSame(0, SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->fresh()->status);
    }

    // S6: the flow CAN be live before the address exists; the
    // landing then omits the address line instead of printing
    // a bare ", ". After Location, the real line appears.
    public function test_a_live_flow_without_an_address_omits_the_address_line(): void
    {
        $business = $this->checkpoint();

        $this->post(route('owner.reward-texts'), [
            'friend_reward_text' => 'A free regular coffee',
            'advance' => '1',
            'presented_reward_text' => 'A free regular coffee',
        ])->assertRedirect();
        $this->post(route('onboarding.card.store'), ['source_id' => 'cnon:ok'])
            ->assertRedirect(route('onboarding.step', 'card'));

        $business = $business->fresh();
        $this->assertNull($business->street);
        $this->assertTrue($business->flowIsActive());

        [, $qrToken] = World::qr($business);
        $landing = $this->get(route('refer.landing', ['token' => $qrToken]));
        $landing->assertOk();
        $landing->assertSee('Corner Deli');
        $landing->assertDontSee('1 Main Street');
        $this->assertStringNotContainsString('>, <', $landing->getContent());

        $this->post(route('onboarding.location.store'), $this->addressPayload())
            ->assertRedirect(route('onboarding.step', 'verify'));

        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertOk()
            ->assertSee('1 Main Street, New York');
    }

    // The Location save completes deferred Square provisioning
    // when the card moment has not happened yet, exactly once.
    public function test_location_save_provisions_square_when_the_card_moment_has_not_happened(): void
    {
        $business = $this->checkpoint();
        $this->assertSame([], $this->squareCustomers->createdForBusinessIds);

        $this->post(route('onboarding.location.store'), $this->addressPayload())
            ->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertSame([$business->id], $this->squareCustomers->createdForBusinessIds);
        $this->assertSame('sq_fake_customer_'.$business->id, $business->fresh()->square_customer_id);

        // A later address edit provisions nothing new.
        $this->post(route('onboarding.location.store'), $this->addressPayload(['street' => '2 Main Street']))
            ->assertRedirect(route('onboarding.step', 'verify'));
        $this->assertSame([$business->id], $this->squareCustomers->createdForBusinessIds);
        $this->assertSame('2 Main Street', $business->fresh()->street);
    }

    // Prior answers stay editable: the Location step reopens
    // with the saved values in the form and a save replaces them.
    public function test_a_saved_address_reopens_prefilled_and_editable(): void
    {
        $business = $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->addressPayload())
            ->assertRedirect(route('onboarding.step', 'verify'));

        $this->get(route('onboarding.step', 'location'))
            ->assertOk()
            ->assertSee('value="1 Main Street"', false)
            ->assertSee('value="New York"', false)
            ->assertSee('value="10001"', false);

        $this->post(route('onboarding.location.store'), $this->addressPayload([
            'street' => '9 Elm Street',
            'postal_code' => '10002',
        ]))->assertRedirect(route('onboarding.step', 'verify'));

        $business = $business->fresh();
        $this->assertSame('9 Elm Street', $business->street);
        $this->assertSame('10002', $business->postal_code);
    }

    // The F-2 lesson pin: a reachable NULL-address state must
    // never 500 an owner surface.
    public function test_null_address_owner_surfaces_render_without_a_500(): void
    {
        $business = $this->checkpoint();
        $this->assertNull($business->street);

        $urls = [
            route('dashboard'),
            route('onboarding.welcome'),
            route('onboarding.step', 'rewards'),
            route('onboarding.step', 'card'),
            route('onboarding.step', 'location'),
            route('onboarding.step', 'verify'),
            route('onboarding.step', 'poster'),
            route('onboarding.step', 'test'),
            route('onboarding.finish'),
            route('settings'),
            route('settings.profile'),
            route('settings.billing'),
            route('settings.rewards'),
            route('owner.qr'),
            route('customers.index'),
            route('referrals.index'),
            route('actions.index'),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $this->assertNotSame(500, $response->getStatusCode(), "500 on {$url}");
            $this->assertContains($response->getStatusCode(), [200, 302], "Unexpected status on {$url}");
        }
    }

    // S7: a checkpoint account left alone runs no timers against
    // itself in this build: it simply waits, resumable.
    public function test_a_checkpoint_account_left_alone_simply_waits(): void
    {
        $business = $this->checkpoint();

        $this->travel(30)->days();

        $business = $business->fresh();
        $this->assertNotNull($business);
        $this->assertNull($business->onboarding_completed_at);
        $this->assertSame('rewards', Onboarding::firstOpenStep(Onboarding::states($business, $business->owner)));
    }
}
