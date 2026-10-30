<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Customer;
use App\Models\FlowSession;
use App\Models\Membership;
use App\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SELF-01 (owner-findings fix wave, 2026-10-25): the owner
 * tested his own link, typed his own number as the "friend",
 * and was only told at the very last step, after verification,
 * that he cannot refer himself. The refusal now surfaces at
 * the claim step, the second the number is entered: the claim
 * POST compares the entered number (normalized by the same
 * usPhone()/E.164 rule the completion check compares) against
 * the referrer's own number and refuses inline with the
 * approved REF-FRIEND-03 wording. The completion check in
 * EnrollmentService::completeClaim is NOT moved; it stays as
 * the fraud backstop, and the third test proves it still
 * refuses a self-referral whose pending was crafted past the
 * early check.
 */
class SelfReferralEarlyRefusalTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    private function flushSessionAndCookies(): void
    {
        $this->app->forgetInstance('session');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    /**
     * Enroll Pat (phone +12125550111) as a member of a fresh
     * business and return [business, membership].
     */
    private function enrolledReferrer(): array
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));

        $membership = Membership::query()->firstOrFail();
        $this->flushSessionAndCookies();
        $this->verify->starts = [];

        return [$business, $membership];
    }

    public function test_the_referrers_own_number_is_refused_at_the_claim_step_itself(): void
    {
        [, $membership] = $this->enrolledReferrer();
        $claim = route('refer.friend.claim', ['token' => $membership->plainShareToken()]);

        // Typed the way a person types it: punctuation and all.
        // The early check compares the same normalized E.164 the
        // completion backstop compares, so the format cannot
        // sneak past it.
        $this->post($claim, [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '(212) 555-0111',
        ])->assertSessionHasErrors([
            'phone' => 'You cannot refer yourself. Please share your link with a friend instead.',
        ]);

        // Nothing was started and nothing was created: no code
        // was sent for the referrer's own number, and no friend
        // record exists for the attempt.
        $this->assertSame([], $this->verify->starts);
        $this->assertSame(0, Referral::count());
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Membership::count());

        // Rendered, the refusal sits at the phone field itself,
        // on the same form the number was typed into.
        $friendPage = route('refer.friend', ['token' => $membership->plainShareToken()]);
        $this->carryFlowCookie($this->get($friendPage)->assertOk());
        $this->followingRedirects()->from($friendPage)->post($claim, [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])
            ->assertOk()
            ->assertSee('id="phone-error"', false)
            ->assertSee('You cannot refer yourself. Please share your link with a friend instead.');

        $this->assertSame([], $this->verify->starts);
    }

    public function test_a_different_number_proceeds_to_verification_as_before(): void
    {
        [, $membership] = $this->enrolledReferrer();

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $membership->plainShareToken()]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->assertCount(1, $this->verify->starts);
        $this->assertSame('+12125550212', $this->verify->starts[0]['phone']);
    }

    public function test_the_completion_backstop_still_refuses_an_owner_number_crafted_past_the_early_check(): void
    {
        [$business, $membership] = $this->enrolledReferrer();
        $ownerPhone = \Tests\Support\World::owner($business)->phone_e164;

        // Start a claim with a normal number, so the early checks
        // pass and a verification is pending...
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $membership->plainShareToken()]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        // ...then rewrite the pending to the business owner's own
        // number. Completion must still refuse it (U9 keeps the
        // EnrollmentService backstop): no referral, and the
        // designed owner_phone screen.
        $session = FlowSession::query()->get()
            ->first(fn (FlowSession $s) => ($s->data['pending']['kind'] ?? null) === 'claim');
        $this->assertNotNull($session, 'No claim pending was stored; the bypass craft has nothing to rewrite.');
        $data = $session->data;
        $data['pending']['phone_e164'] = $ownerPhone;
        $session->data = $data;
        $session->save();

        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertOk()
            ->assertSee("That is the business's own number", false)
            ->assertSee('no reward can be earned with it', false);

        $this->assertSame(0, Referral::count());
    }

    public function test_the_completion_backstop_still_refuses_a_self_referral_crafted_past_the_early_check(): void
    {
        [, $membership] = $this->enrolledReferrer();

        // Start a claim with a different number, so the early
        // check passes and a verification is pending...
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $membership->plainShareToken()]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550212', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        // ...then rewrite the server-side pending to the
        // referrer's own number, the crafted request the early
        // check exists in front of. Completion must still
        // refuse it: no referral, and the designed self screen.
        $session = FlowSession::query()->get()
            ->first(fn (FlowSession $s) => ($s->data['pending']['kind'] ?? null) === 'claim');
        $this->assertNotNull($session, 'No claim pending was stored; the bypass craft has nothing to rewrite.');
        $data = $session->data;
        $data['pending']['phone_e164'] = '+12125550111';
        $session->data = $data;
        $session->save();

        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertOk()
            ->assertSee('You cannot refer yourself')
            ->assertSee('This is your own referral link. Share it with a friend to earn your reward.');

        $this->assertSame(0, Referral::count());
    }
}
