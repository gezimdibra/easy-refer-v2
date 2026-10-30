<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The /refer flow (spec Section 3), driven end to end over HTTP
 * with the Twilio gateways faked: QR landing, enrollment, Verify,
 * share, friend claim, dedupe, velocity, expiry. Test IDs map to
 * the Phase 2 gate rows in test-matrix.md.
 */
class ReferFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->sms = new FakeSmsGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    /**
     * This Laravel version's test client does not carry response
     * cookies automatically: replay the flow cookie explicitly,
     * exactly as a browser would (the value is the app's encrypted
     * form; the app decrypts it on the next request).
     */
    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    private function enrollCustomer(Business $business, string $qrToken, string $phone = '+12125550111', string $first = 'Pat', array $extra = []): void
    {
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());

        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), array_merge([
            'first_name' => $first,
            'last_name' => 'Lee',
            'phone' => $phone,
            // Step-1 consent gates the flow (round-2 audit F02);
            // the shared helper enrolls a consenting customer.
            'consent' => '1',
        ], $extra))->assertRedirect(route('refer.verify')));

        // Verification rotates the flow token; carry the rotated
        // cookie the way a browser would.
        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));
    }

    public function test_qr_landing_shows_business_and_reward_headline(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertOk()
            ->assertSee($business->name)
            // The designed R1 headline (F-14, F-2): the friend
            // reward composed by Business::friendRewardHeadline().
            // The fixture reward already says "your first visit",
            // so the suffix is not appended and the phrase appears
            // exactly once.
            ->assertSee('$10 off your first visit')
            ->assertDontSee('on your first visit on your first visit')
            ->assertSee('Enter your phone, get your own link, share it with a friend')
            ->assertSee('First name')
            ->assertSee('Mobile phone')
            ->assertDontSee('Use the name on your ID')
            ->assertSee('1 Test Street, New York')
            ->assertSee('I agree to receive text messages about my rewards');
    }

    public function test_qr_landing_headline_never_doubles_the_first_visit_phrase(): void
    {
        // A reward naming the first visit in its own words (the
        // barber case the audit captured, F-2): the suffix stays
        // off, so the headline is the reward alone (release 35:
        // no generated "Get" prefix).
        $barber = World::business(['friend_reward_text' => '$10 off your first cut']);
        [, $barberToken] = World::qr($barber);

        $this->get(route('refer.landing', ['token' => $barberToken]))
            ->assertOk()
            ->assertSee('$10 off your first cut')
            ->assertDontSee('first cut on your first visit');

        // Amendment 58 [REF-POSTER-04]: the QR landing headline
        // is the poster's give/get headline now (promise echo),
        // so a reward with no first-visit wording no longer
        // gains the suffix ON THIS PAGE — the suffix composer
        // still governs the friend-side pages, pinned there.
        // World defaults make this business SPLIT (referrer
        // "$10 off"), so the landing carries the split form.
        $upgrade = World::business(['friend_reward_text' => 'A free upgrade']);
        [, $upgradeToken] = World::qr($upgrade);

        $this->get(route('refer.landing', ['token' => $upgradeToken]))
            ->assertOk()
            ->assertSee('Give a friend A free upgrade. Get $10 off when they visit.', false);
    }

    public function test_qr_landing_for_unknown_or_revoked_code_is_the_generic_page(): void
    {
        $business = World::business();
        [$qr, $qrToken] = World::qr($business);

        $this->get(route('refer.landing', ['token' => str_repeat('a', 43)]))
            ->assertNotFound()
            ->assertSee('This code is not active')
            ->assertSee('Please ask the business for help')
            ->assertDontSee($business->name);

        $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();

        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertNotFound()
            ->assertSee('This code is not active')
            ->assertDontSee($business->name);
    }

    public function test_qr_landing_while_billing_is_off_shows_the_not_active_page_naming_the_business(): void
    {
        // Section 9.9 setup mode: until a card is on file the public
        // flow is off. FE-INACTIVE-01 (fresh-eyes fix train,
        // 2026-10-25) reverses this pin's earlier no-name rule:
        // all three cold auditors flagged that the page never
        // named the business, leaving a customer who scanned a
        // real code in a real shop unsure they were in the right
        // place. The page now names the business (body line and
        // title); the designed message, the 404, and the
        // no-program-details rule stand — no reward text leaks.
        // RE-DERIVED (activation redesign, 2026-10-30): a
        // cardless business with billing 'none' is LIVE under
        // the allowance gate; the not-active state here is
        // reached through the reward leg (no friend reward
        // text), which renders the same generic page.
        $business = World::business(['billing_status' => 'none', 'friend_reward_text' => null]);
        [, $qrToken] = World::qr($business);

        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertNotFound()
            ->assertSee('This code is not active')
            ->assertSee($business->name)
            ->assertSee('<title>'.$business->name.' | Easy Refer</title>', false)
            ->assertDontSee('$10 off');
    }

    public function test_enrollment_completes_through_verify_and_lands_on_the_share_page(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->enrollCustomer($business, $qrToken);

        $customer = Customer::query()->where('phone_e164', '+12125550111')->firstOrFail();
        $membership = Membership::query()->where('business_id', $business->id)->where('customer_id', $customer->id)->firstOrFail();

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Share your link')
            ->assertSee('invited you to get your Reward:')
            ->assertSee(route('refer.friend', ['token' => $membership->plainShareToken()]));

        // The scan, enrollment, and verification events exist, and
        // E6 fired for the business's first scan.
        $this->assertDatabaseHas('referral_events', ['business_id' => $business->id, 'event_type' => 'scan']);
        $this->assertDatabaseHas('referral_events', ['business_id' => $business->id, 'event_type' => 'enrollment']);
        $this->assertDatabaseHas('referral_events', ['business_id' => $business->id, 'event_type' => 'verification_passed']);
        $this->assertDatabaseHas('attribution_events', ['business_id' => $business->id, 'event_name' => 'qr_first_scan']);
    }

    public function test_abandoned_verification_writes_nothing(): void
    {
        // REF-22: no customer, membership, or referral row exists
        // before a successful verification.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Membership::count());
        $this->assertSame(0, Referral::count());
        $this->assertSame(0, ReferralEvent::count());
    }

    public function test_wrong_codes_are_bounded_and_indistinguishable(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('refer.verify.check'), ['code' => '000000'])
                ->assertRedirect(route('refer.verify'));
            $this->get(route('refer.verify'))->assertSee('That code did not match');
        }

        // The sixth check is past the per-verification budget.
        $this->post(route('refer.verify.check'), ['code' => '000000'])
            ->assertOk()
            ->assertSee('Too many tries. Wait 10 minutes and we will send a fresh code.')
            ->assertSee('Wrong number?');
        $this->assertSame(0, Customer::count());
    }

    public function test_the_last_wrong_code_says_what_to_do_next(): void
    {
        // T4-F5: at 0 tries left the countdown alone left the
        // customer guessing; the message now names the way out
        // (Resend gets a fresh code and a fresh try budget).
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('refer.verify.check'), ['code' => '000000'])
                ->assertRedirect(route('refer.verify'));
        }
        $this->get(route('refer.verify'))->assertSee('You have 1 try left.');

        $this->post(route('refer.verify.check'), ['code' => '000000'])
            ->assertRedirect(route('refer.verify'));
        $this->get(route('refer.verify'))
            ->assertSee('That was the last try for this code. Tap Resend code to get a fresh one.')
            ->assertDontSee('You have 0 tries left.');
    }

    public function test_landline_falls_back_to_a_voice_call(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->verify->landlinePhone = '+12125550111';

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        // One SMS start (blocked) and one voice start.
        $this->assertSame(['sms', 'call'], array_column($this->verify->starts, 'channel'));

        $this->get(route('refer.verify'))->assertSee('we are calling it with your code instead');
    }

    public function test_non_us_numbers_are_rejected_before_any_provider_call(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk();
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+442071234567',
        ])->assertSessionHasErrors('phone');

        $this->assertSame([], $this->verify->starts);
    }

    public function test_enrollment_without_consent_is_blocked_and_sends_no_code(): void
    {
        // Round-2 audit F02: the step-1 consent panel used to be
        // decorative. A submission without consent advanced to
        // step 2 and a code was sent, under copy that reads as a
        // real agreement. Consent now gates the flow.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertSessionHasErrors('consent');

        // No code was requested from the provider, nothing is
        // pending, and the verify step bounces back to this
        // business's start (T4-F4) instead of showing the code form.
        $this->assertSame([], $this->verify->starts);
        $this->get(route('refer.verify'))->assertRedirect(route('refer.landing', ['token' => $qrToken]));
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Membership::count());
    }

    public function test_enrollment_with_consent_advances_and_sends_one_code(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->assertCount(1, $this->verify->starts);
        $this->assertSame('sms', $this->verify->starts[0]['channel']);
    }

    public function test_returning_customer_gets_the_same_share_token_back(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->enrollCustomer($business, $qrToken);
        $first = Membership::query()->firstOrFail()->plainShareToken();

        // A fresh browser (new flow session) verifies again.
        $this->flushSessionAndCookies();
        $this->enrollCustomer($business, $qrToken);

        $this->assertSame($first, Membership::query()->firstOrFail()->plainShareToken());
        $this->assertSame(1, Membership::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_friend_claim_without_consent_is_blocked_and_sends_no_code(): void
    {
        // U1 (launch-readiness F-1): the friend claim used to send
        // the verification code and complete enrollment with the
        // text-consent box unchecked, while enroll() hard-refused
        // the identical box. The claim now mirrors the enroll gate
        // (FIX-02): no consent, no code, no pending verification.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'send_link' => '1',
        ])->assertSessionHasErrors([
            'consent' => 'Please agree to the texts so we can send your code and your reward updates.',
        ]);

        // No code was requested from the provider, nothing is
        // pending, and the verify step bounces instead of showing
        // the code form.
        $this->assertSame([], $this->verify->starts);
        $this->get(route('refer.verify'))->assertRedirect(route('home'));
        $this->assertSame(0, Referral::count());
        $this->assertSame(1, Membership::count());
    }

    public function test_friend_claim_with_consent_advances_and_sends_one_code(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->assertCount(1, $this->verify->starts);
        $this->assertSame('+12125550212', $this->verify->starts[0]['phone']);
    }

    public function test_friend_claim_without_consent_renders_the_consent_error_at_the_consent_row(): void
    {
        // U1R (release 28 Gate 3 flows FAIL, leg G3.4(a)(1)): U1's
        // gate refused the unchecked claim and sent no code, but
        // the designed error rendered nowhere on the claim page:
        // friend.blade.php had no @error('consent') render and no
        // aria wiring on the checkbox, so the friend saw only the
        // summary heading with nothing stated anywhere. The enroll
        // sibling (landing.blade.php) renders the same error at its
        // consent row; the claim page must reach parity: the
        // verbatim error is stated at the consent row, wired to
        // the checkbox. RT-3 (2026-10-27) extends the parity: the
        // summary must also LIST the problem, per the pins below.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'send_link' => '1',
        ])->assertSessionHasErrors([
            'consent' => 'Please agree to the texts so we can send your code and your reward updates.',
        ]);
        $this->assertSame([], $this->verify->starts);

        $html = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();

        // The summary counts the consent problem (its one item).
        $this->assertStringContainsString('One thing to fix:', $html);
        // The verbatim error renders at the consent row, in the
        // sibling's exact element shape.
        $this->assertMatchesRegularExpression(
            '/<p class="form-error mt-2" id="consent-error">Please agree to the texts so we can send your code and your reward updates\.<\/p>/',
            $html
        );
        // The checkbox is wired to that error, as the sibling does.
        $this->assertMatchesRegularExpression(
            '/<input type="checkbox"[^>]*name="consent"[^>]*aria-invalid="true"[^>]*aria-describedby="consent-error"/s',
            $html
        );
        // RT-3 (owner correction, 2026-10-27): the summary also
        // LISTS the problem, as an anchor to the consent field.
        // The live claim form showed "One thing to fix:" with
        // nothing after it; the named check is that the message
        // text renders INSIDE the summary region, not only at
        // the field below the fold.
        $this->assertMatchesRegularExpression(
            '/data-error-summary>(?:(?!<\/div>).)*Please agree to the texts so we can send your code and your reward updates\./s',
            $html
        );
        $this->assertStringContainsString('<a class="underline" href="#consent">Please agree to the texts', $html);
        // Stated exactly twice page-wide by design now: once in
        // the summary list, once at the field.
        $this->assertSame(2, substr_count($html, 'Please agree to the texts so we can send your code and your reward updates.'));
    }

    public function test_friend_claim_with_consent_shows_no_consent_error(): void
    {
        // U1R control for the render pin above, same assertion
        // shape on the checked path: no consent error anywhere.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $claim = $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify'))->assertSessionHasNoErrors();
        $this->carryFlowCookie($claim);

        $html = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();

        $this->assertStringNotContainsString('consent-error', $html);
        $this->assertStringNotContainsString('Please agree to the texts', $html);
        $this->assertMatchesRegularExpression(
            '/<input type="checkbox"[^>]*name="consent"[^>]*aria-invalid="false"/s',
            $html
        );
    }

    public function test_enrollment_without_consent_renders_the_consent_error_at_the_consent_row(): void
    {
        // U1R sibling parity guard: the enroll original's render,
        // which the claim fix copies, stays exactly as shipped.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertSessionHasErrors([
            'consent' => 'Please agree to the texts so we can send your code and your reward updates.',
        ]);

        $html = $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk()->getContent();

        $this->assertStringContainsString('One thing to fix:', $html);
        $this->assertMatchesRegularExpression(
            '/<p class="form-error mt-2" id="consent-error">Please agree to the texts so we can send your code and your reward updates\.<\/p>/',
            $html
        );
        // RT-3 parity with the claim form: the summary lists the
        // problem too (anchor to the field), so the message is
        // stated exactly twice by design.
        $this->assertStringContainsString('<a class="underline" href="#consent">Please agree to the texts', $html);
        $this->assertSame(2, substr_count($html, 'Please agree to the texts so we can send your code and your reward updates.'));
    }

    public function test_friend_claim_summary_lists_every_problem_not_just_the_count(): void
    {
        // RT-3, the multi-problem form of the named check: two
        // problems render "2 things to fix:" AND both messages
        // listed in the summary as anchors to their fields. (Two
        // validation problems: the consent gate runs only after
        // validation passes, so it never shares a summary with
        // field errors.)
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'last_name' => 'Kim', 'phone' => '',
        ])->assertSessionHasErrors(['first_name', 'phone']);

        $html = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();

        $this->assertStringContainsString('2 things to fix:', $html);
        $this->assertStringContainsString('<a class="underline" href="#first_name">Enter your first name.</a>', $html);
        $this->assertMatchesRegularExpression(
            '/<a class="underline" href="#phone">[^<]+<\/a>/',
            $html
        );
    }

    public function test_friend_claim_creates_the_referral_and_lands_on_the_redemption_page(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($business, $qrToken);
        $membership = Membership::query()->firstOrFail();
        // RT-2: the referrer's link predates the claim by more
        // than the 25-minute scan-to-claim gap.
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $this->flushSessionAndCookies();
        $friendPage = $this->get(route('refer.friend', ['token' => $membership->plainShareToken()]));
        $this->carryFlowCookie($friendPage);
        $friendPage
            ->assertOk()
            ->assertSee('Invited by Pat')
            ->assertDontSee('2125550111')
            ->assertDontSee('+12125550111');

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $membership->plainShareToken()]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $response = $this->post(route('refer.verify.check'), ['code' => '123456']);
        $referral = Referral::query()->firstOrFail();
        $response->assertRedirect(route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted]));

        $this->get(route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted]))
            ->assertOk()
            // R4-U2: the show screen's headline is the prize, in the
            // poster's composed sentence; the instruction H1 retired.
            ->assertSee($business->friendRewardHeadline())
            ->assertSee('Good until '.$business->localTime($referral->expires_at)->format('F j, Y'));
    }

    public function test_self_referral_by_phone_earns_nothing(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($business, $qrToken);
        $membership = Membership::query()->firstOrFail();
        $this->flushSessionAndCookies();
        $this->verify->starts = [];

        // SELF-01: the refusal now lands at the claim step
        // itself, the second the number is entered; the number
        // never reaches verification. (The completion backstop
        // is covered by SelfReferralEarlyRefusalTest.)
        $this->post(route('refer.friend.claim', ['token' => $membership->plainShareToken()]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertSessionHasErrors([
            'phone' => 'You cannot refer yourself. Please share your link with a friend instead.',
        ]);

        $this->assertSame([], $this->verify->starts);
        $this->assertSame(0, Referral::count());
    }

    public function test_business_owner_phone_earns_nothing(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        $this->verify->starts = [];

        // U9 (persona F-4): the business's-own-number stop now
        // fires at the claim form itself, beside SELF-01, with
        // the flow/self owner_phone page's designed words as the
        // field error. No code is sent to the business's phone.
        // (The completion backstop is covered in
        // SelfReferralEarlyRefusalTest.)
        $ownerPhone = substr(World::owner($business)->phone_e164, 2);
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Boss', 'last_name' => 'Man', 'phone' => $ownerPhone, 'consent' => '1',
        ])->assertSessionHasErrors([
            'phone' => "That is the business's own number. This number belongs to {$business->name}, so no reward can be earned with it. Please use the customer's own mobile number.",
        ]);

        $this->assertSame([], $this->verify->starts);
        $this->assertSame(0, Referral::count());
        $this->assertSame(1, Membership::count());
    }

    public function test_dedupe_returns_the_existing_pending_referral(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membershipA, $shareA] = World::membership($business, $referrer);
        $other = World::customer('+12125550888', 'Ola', 'Nord');
        [$membershipB, $shareB] = World::membership($business, $other);
        $friend = World::customer('+12125550777', 'Fri', 'End');

        [$existing, $existingToken] = World::referral($membershipA, $friend);

        // The friend arrives through the OTHER customer's link and
        // claims again: the same pending referral answers.
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareB]), [
            'first_name' => 'Fri', 'last_name' => 'End', 'phone' => '2125550777', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.redeem.page', ['token' => $existingToken]));

        $this->assertSame(1, Referral::count());
    }

    public function test_expired_pending_referral_frees_the_dedupe_slot(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        // RT-2: the referrer's link predates the claim by more
        // than the 25-minute scan-to-claim gap.
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();
        $friend = World::customer('+12125550777', 'Fri', 'End');

        World::referral($membership, $friend, ['expires_at' => now()->subDay()]);

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Fri', 'last_name' => 'End', 'phone' => '2125550777', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $this->assertSame(2, Referral::count());
        $this->assertSame(1, Referral::query()->where('state', Referral::STATE_EXPIRED)->count());
        $this->assertSame(1, Referral::query()->where('state', Referral::STATE_PENDING)->count());
    }

    public function test_velocity_cap_records_the_referral_not_held_for_review(): void
    {
        // Option B (2026-10-28), design-pass T1: past the daily
        // cap at CLAIM time, the referral is still created exactly
        // like an unflagged claim — pending, unheld, no
        // flag_raised. The review item is born at redemption,
        // when the recheck converts the breach into a held reward.
        $business = World::business(['daily_referral_cap' => 1]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        // RT-2: the referrer's link predates the claim by more
        // than the 25-minute scan-to-claim gap.
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();
        $friendOne = World::customer('+12125550777', 'One', 'A');
        World::referral($membership, $friendOne);

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Two', 'last_name' => 'B', 'phone' => '2125550666', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $created = Referral::query()->where('referred_customer_id', '!=', $friendOne->id)->latest('id')->firstOrFail();
        $this->assertSame(Referral::STATE_PENDING, $created->state);
        $this->assertFalse((bool) $created->hold);
        $this->assertNull($created->hold_rules);
        $this->assertNull($created->held_at);
        $this->assertSame(0, Referral::query()->where('hold', true)->count());
        $this->assertDatabaseMissing('referral_events', ['event_type' => 'flag_raised', 'business_id' => $business->id]);
    }

    public function test_friend_link_sms_goes_only_with_consent(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        // RT-2: the referrer's link predates the claim by more
        // than the 25-minute scan-to-claim gap.
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1', 'send_link' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('+12125550212', $this->sms->sent[0]['to']);
        $this->assertStringContainsString('Reg invited you', $this->sms->sent[0]['body']);
        $this->assertDatabaseHas('sms_messages', ['template_key' => 'friend_link', 'consent_basis' => 'customer_checkbox']);
    }

    public function test_claim_without_the_sms_checkbox_sends_no_text(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        // RT-2: the referrer's link predates the claim by more
        // than the 25-minute scan-to-claim gap.
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $this->assertCount(0, $this->sms->sent);
    }

    public function test_verify_budgets_stop_provider_calls(): void
    {
        // Three starts for one phone in an hour is the budget; the
        // fourth never reaches the provider (REF-12).
        $business = World::business();
        [, $qrToken] = World::qr($business);

        for ($i = 0; $i < 3; $i++) {
            $this->flushSessionAndCookies();
            $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk();
            $this->post(route('refer.enroll', ['token' => $qrToken]), [
                'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
            ])->assertRedirect(route('refer.verify'));
        }

        $this->flushSessionAndCookies();
        $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk();
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertOk()->assertSee('Too many tries');

        $this->assertCount(3, $this->verify->starts);
    }

    public function test_pending_redemption_page_expires_on_read(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral, $token] = World::referral($membership, $friend, ['expires_at' => now()->subHour()]);

        $this->get(route('refer.redeem.page', ['token' => $token]))
            ->assertOk()
            ->assertSee('This reward expired on');

        $this->assertSame(Referral::STATE_EXPIRED, $referral->fresh()->state);
    }

    public function test_unknown_friend_and_redemption_links_are_generic(): void
    {
        $junk = str_repeat('b', 43);

        $this->get(route('refer.friend', ['token' => $junk]))
            ->assertNotFound()
            ->assertSee('Something is not right with that link');

        $this->get(route('refer.redeem.page', ['token' => $junk]))
            ->assertNotFound()
            ->assertSee('Something is not right with that link');

        $this->get(route('refer.claim.page', ['token' => $junk]))
            ->assertNotFound()
            ->assertSee('Something is not right with that link');
    }

    public function test_landing_discloses_only_the_business_name_and_reward(): void
    {
        // XT-39: no owner email, no owner phone, no other customer
        // data on the public landing.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $owner = World::owner($business);
        $stranger = World::customer('+12125550444', 'Zelda', 'Stranger');

        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertOk()
            ->assertDontSee($owner->email)
            ->assertDontSee('Zelda')
            ->assertDontSee($business->contact_email)
            ->assertDontSee($business->contact_phone_e164);
    }

    public function test_claim_page_reveals_the_vesting_date_only_inside_the_referrers_flow_session(): void
    {
        // RDM-19 / G-17: a bare link shows the waiting state with
        // no date; the referrer's own verified flow session sees
        // the date.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($business, $qrToken);
        $membership = Membership::query()->firstOrFail();
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$reward, $claimToken] = World::reward($membership, $referral, [
            'state' => \App\Models\Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
            'notification_state' => 'queued',
        ]);
        $date = $business->localTime($reward->available_at)->format('F j, Y');

        // Inside the live session (the enrollment above): shown.
        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Your reward is on its way')
            ->assertSee($date);

        // A bare browser: waiting, no date anywhere.
        $this->flushSessionAndCookies();
        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Your reward is on its way')
            ->assertDontSee($date);
    }

    public function test_review_button_appears_only_on_completed_receipts_with_a_review_url(): void
    {
        // F4 (spec Section 6.1 item 5): the redeemed receipt and
        // the claimed receipt carry the review button when the
        // business set a review URL; pending pages never do.
        $business = World::business(['google_review_url' => 'https://g.page/example']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$redeemed, $redeemedToken] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        $friendB = World::customer('+12125550666', 'Bee', 'Two');
        [, $pendingToken] = World::referral($membership, $friendB);

        $this->get(route('refer.redeem.page', ['token' => $redeemedToken]))
            ->assertOk()
            ->assertSee('Leave '.$business->name.' a Google review')
            ->assertSee('rel="noopener"', false);

        $this->get(route('refer.redeem.page', ['token' => $pendingToken]))
            ->assertOk()
            ->assertDontSee('Google review');

        [$reward, $claimToken] = World::reward($membership, $redeemed, [
            'state' => \App\Models\Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Leave '.$business->name.' a Google review');
    }

    public function test_flow_cookie_is_separate_from_the_owner_session(): void
    {
        // AUTH-40: the flow cookie is its own cookie with the
        // session flags and the mount path; an owner session alone
        // opens nothing inside the customer flow.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $response = $this->get(route('refer.landing', ['token' => $qrToken]));
        $flowCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $flowCookie = $cookie;
            }
        }

        $this->assertNotNull($flowCookie);
        $this->assertTrue($flowCookie->isHttpOnly());
        $this->assertSame((bool) config('session.secure'), $flowCookie->isSecure());
        // Thirty minutes, per config (security.flow.ttl_minutes):
        // the Set-Cookie header carries Max-Age=1800.
        $setCookie = collect($response->headers->get('set-cookie'))
            ->first(fn (string $h) => str_starts_with($h, 'er2_flow='));
        $this->assertNotNull($setCookie);
        $this->assertMatchesRegularExpression('/Max-Age=(\d+)/', $setCookie);
        preg_match('/Max-Age=(\d+)/', $setCookie, $m);
        $this->assertGreaterThan(1700, (int) $m[1]);
        $this->assertLessThanOrEqual(1800, (int) $m[1]);
        // The path is the mount's one config point (spec 11.1):
        // /new at the staged mount, / at the root mount.
        $this->assertSame((string) config('session.path'), $flowCookie->getPath());
        $this->assertSame('lax', strtolower((string) $flowCookie->getSameSite()));

        $this->actingAs(World::owner($business))
            ->get(route('refer.share'))
            ->assertRedirect(route('home'));
    }

    private function flushSessionAndCookies(): void
    {
        $this->app->forgetInstance('session');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
