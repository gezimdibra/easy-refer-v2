<?php

namespace Tests\Feature\Flow;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-08 Unit 1 (idea-08-design-pass.md, Variant B-prime;
 * coordinator rulings 2026-10-29: Unit 2 deferred, M1 standard
 * review): the friend-to-referrer prime's instrumentation and
 * its one passive home.
 *
 * What is under pin:
 *  - M1 (Section 7.3): redeemPage rendering state redeemed WITH
 *    a non-null prime payload writes exactly one prime_shown
 *    row per referral id in the append-only referral_events
 *    log; a null prime (flow paused after the visit, or any
 *    non-redeemed state) writes none.
 *  - [F2R-02] (Sections 2.4, 4, 8.3): one reminder line on the
 *    friend's own share page, directly above the prospective
 *    line, only while the member arrived as a redeemed friend
 *    at this business AND has created zero downstream referrals
 *    here AND the flow is live AND the provenance name (the
 *    referrer of the member's most recent redeemed referral)
 *    resolves. The first downstream referral retires it for
 *    good (F2R-QUIET); failed claim kinds (self_phone,
 *    owner_phone, existing) create no row and do not retire it.
 *  - The MEASURE query (Sections 7.2, 7.4), written verbatim
 *    into a helper below: of friends whose referral redeemed,
 *    the share who create at least one downstream referral
 *    within 14 days of their redemption. Link generation is
 *    automatic and is NOT the metric.
 *  - Friction (Section 13): own code live at 0 taps on both
 *    paths; the first share is the page's existing share
 *    action (1 tap + the OS channel choice).
 *  - OD-2 concordance (pin 7): with the referrer text blank,
 *    the receipt prime, the share prospective line, and the
 *    reward that actually mints all carry the friend text.
 */
class FriendToReferrerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    /**
     * Enroll (or re-enroll) a customer through the store QR so
     * the flow session is verified as that customer, exactly as
     * a returning member lands on their share page. A customer
     * who already holds a membership at the business gets that
     * same membership back (findOrCreateWithToken).
     */
    private function enrollAs(Business $business, string $qrToken, string $first, string $last, string $phone): Membership
    {
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());

        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));

        $customer = \App\Models\Customer::query()->where('phone_e164', $phone)->firstOrFail();

        return Membership::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->firstOrFail();
    }

    private function shareHtml(): string
    {
        $response = $this->get(route('refer.share'))->assertOk();
        $this->carryFlowCookie($response);

        return $response->getContent();
    }

    /**
     * A redeemed visit: Reg referred Fri; Fri visited, redeemed,
     * and holds their own membership (minted at claim completion
     * in the real flow).
     *
     * @return array{0: Business, 1: Membership, 2: string, 3: Membership, 4: string, 5: Referral}
     */
    private function redeemedVisit(array $businessOverrides = []): array
    {
        $business = World::business($businessOverrides);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership, $friendShareToken] = World::membership($business, $friend);
        [$referral, $redeemToken] = World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        return [$business, $referrerMembership, $redeemToken, $friendMembership, $friendShareToken, $referral];
    }

    private function pauseBusiness(Business $business): void
    {
        $business->forceFill(['billing_status' => 'past_due'])->save();
        Carbon::setTestNow(now()->subDays(30));
        AuditLog::write('billing.past_due', null, [], $business->fresh(), null);
        Carbon::setTestNow(null);
    }

    /**
     * The MEASURE query, verbatim from design Section 7.2:
     * the friend's membership at the business (unique pair),
     * then any referrals row R2 whose referrer is that
     * membership and whose created_at is within 14 days after
     * R1's redeemed_at.
     */
    private function activatedWithin14Days(Referral $r1): bool
    {
        $membership = Membership::query()
            ->where('business_id', $r1->business_id)
            ->where('customer_id', $r1->referred_customer_id)
            ->first();

        if ($membership === null) {
            return false;
        }

        return Referral::query()
            ->where('referrer_membership_id', $membership->id)
            ->where('created_at', '<=', $r1->redeemed_at->copy()->addDays(14))
            ->exists();
    }

    // ---------------------------------------------------- M1

    public function test_prime_shown_is_recorded_exactly_once_per_referral_across_receipt_reviews(): void
    {
        [$business, , $redeemToken, $friendMembership, , $referral] = $this->redeemedVisit();

        for ($i = 0; $i < 3; $i++) {
            $this->get(route('refer.redeem.page', ['token' => $redeemToken]))
                ->assertOk()
                ->assertSee('Pass it on');
        }

        $rows = ReferralEvent::query()->where('event_type', 'prime_shown')->get();
        $this->assertCount(1, $rows, 'three receipt views write exactly one prime_shown row');
        $this->assertSame($referral->id, $rows[0]->referral_id);
        $this->assertSame($business->id, $rows[0]->business_id);
        $this->assertSame($friendMembership->id, $rows[0]->membership_id);
        $this->assertSame('customer', $rows[0]->actor);
    }

    public function test_prime_shown_is_not_recorded_when_the_prime_payload_is_null_or_the_state_is_not_redeemed(): void
    {
        // Flow paused after the visit: receipt facts intact,
        // prime gone, and no impression row (the S8 cohort is
        // enumerable precisely by this absence).
        [$business, , $redeemToken] = $this->redeemedVisit();
        $this->pauseBusiness($business);

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Pass it on', $html);
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'prime_shown')->count());

        // A pending (show-state) receipt carries no prime and
        // records nothing either.
        $business2 = World::business();
        $referrer2 = World::customer('+12125550998', 'Reg', 'Ular');
        [$membership2] = World::membership($business2, $referrer2);
        $friend2 = World::customer('+12125550776', 'Fri', 'End');
        World::membership($business2, $friend2);
        [, $pendingToken] = World::referral($membership2, $friend2, ['state' => Referral::STATE_PENDING]);

        $this->get(route('refer.redeem.page', ['token' => $pendingToken]))->assertOk();
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'prime_shown')->count());
    }

    // ---------------------------------------------------- F2R-02 presence

    public function test_share_page_shows_the_reminder_naming_the_latest_redeemed_referrals_referrer(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $reg = World::customer('+12125550999', 'Reg', 'Ular');
        [$regMembership] = World::membership($business, $reg);
        $ana = World::customer('+12125550997', 'Ana', 'Other');
        [$anaMembership] = World::membership($business, $ana);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership] = World::membership($business, $friend);

        // Two redeemed visits by the same friend: the counted
        // pair key admits one live-or-counted row, so the older
        // visit carries the superseded stamp the dedupe
        // resolution itself uses; its state stays redeemed.
        World::referral($regMembership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now()->subDays(5),
            'pair_superseded_at' => now()->subDays(4),
        ]);
        World::referral($anaMembership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now()->subDay(),
        ]);

        $this->enrollAs($business, $qrToken, 'Fri', 'End', '+12125550777');
        $html = $this->shareHtml();

        $line = 'You found '.$business->name.' through Ana, the same way your friends can find it through you.';
        $this->assertStringContainsString($line, $html);
        $this->assertStringNotContainsString('through Reg,', $html);
        // Directly above the prospective line (Section 8.3).
        $this->assertLessThan(
            strpos($html, 'When a friend visits '.$business->name.' with your link'),
            strpos($html, $line),
            'the reminder sits directly above the prospective line',
        );
        // Friction, Path B (Section 13): the page's own share
        // actions are on this same page, below the line; no
        // extra step stands between the line and a share.
        $this->assertLessThan(
            strpos($html, 'Share your link'),
            strpos($html, $line),
            'the share action is reachable on the same page, below the line',
        );
    }

    public function test_share_page_line_is_absent_for_an_enrolled_only_member(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->enrollAs($business, $qrToken, 'Pat', 'Lee', '+12125550111');
        $html = $this->shareHtml();

        // The control member's page is otherwise the shipped
        // page: greeting and prospective line present, reminder
        // absent.
        $this->assertStringContainsString('When a friend visits '.$business->name.' with your link', $html);
        $this->assertStringNotContainsString('the same way your friends can find it through you', $html);
    }

    public function test_share_page_line_is_absent_when_the_only_friend_referral_is_pending_or_expired(): void
    {
        foreach ([Referral::STATE_PENDING, Referral::STATE_EXPIRED] as $state) {
            $business = World::business();
            [, $qrToken] = World::qr($business);
            $referrer = World::customer('+12125550999', 'Reg', 'Ular');
            [$referrerMembership] = World::membership($business, $referrer);
            $friend = World::customer('+12125550777', 'Fri', 'End');
            World::membership($business, $friend);
            World::referral($referrerMembership, $friend, ['state' => $state]);

            $this->enrollAs($business, $qrToken, 'Fri', 'End', '+12125550777');
            $html = $this->shareHtml();

            $this->assertStringNotContainsString(
                'the same way your friends can find it through you',
                $html,
                "no visit happened in the {$state} state, so no reminder may render",
            );
        }
    }

    // ---------------------------------------------------- F2R-QUIET retirement

    public function test_the_line_survives_failed_claim_kinds_and_retires_on_the_first_downstream_referral(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership] = World::membership($business, $friend);
        World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        // A third person already holds a live pending referral
        // from Reg: a claim through the friend's link resolves
        // to that governing row ('existing') and creates no row
        // for the friend.
        $third = World::customer('+12125550321', 'Thi', 'Rd');
        World::referral($referrerMembership, $third, ['state' => Referral::STATE_PENDING]);

        $friendMembership->forceFill(['created_at' => now()->subHour()])->save();
        $this->enrollAs($business, $qrToken, 'Fri', 'End', '+12125550777');
        $lineFragment = 'the same way your friends can find it through you';
        $this->assertStringContainsString($lineFragment, $this->shareHtml());

        $enrollment = app(EnrollmentService::class);

        // Gaming attempt, friend party: the friend claims
        // through their OWN link with their own phone.
        $self = $enrollment->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Fri', 'last_name' => 'End',
            'phone_e164' => '+12125550777', 'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('self_phone', $self['kind']);
        $this->assertStringContainsString($lineFragment, $this->shareHtml());

        // Gaming attempt, owner party: the business's own
        // number through the friend's link.
        $ownerPhone = World::owner($business)->phone_e164;
        $ownerClaim = $enrollment->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Own', 'last_name' => 'Er',
            'phone_e164' => $ownerPhone, 'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('owner_phone', $ownerClaim['kind']);
        $this->assertStringContainsString($lineFragment, $this->shareHtml());

        // The 'existing' outcome: no row is created for the
        // friend, so the line stays.
        $existing = $enrollment->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Thi', 'last_name' => 'Rd',
            'phone_e164' => '+12125550321', 'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('existing', $existing['kind']);
        $this->assertSame($referrerMembership->id, $existing['referral']->referrer_membership_id);
        $this->assertStringContainsString($lineFragment, $this->shareHtml());

        // The first real downstream referral retires the line
        // for good, on every later render.
        $created = $enrollment->completeClaim($friendMembership->refresh(), [
            'first_name' => 'New', 'last_name' => 'Pal',
            'phone_e164' => '+12125550444', 'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('created', $created['kind']);
        $this->assertSame($friendMembership->id, $created['referral']->referrer_membership_id);
        $this->assertStringNotContainsString($lineFragment, $this->shareHtml());
        $this->assertStringNotContainsString($lineFragment, $this->shareHtml());
    }

    // ---------------------------------------------------- flow-dead silence

    public function test_the_line_is_absent_when_the_business_pauses_or_closes_after_the_visit(): void
    {
        // Paused (dunning grace closed).
        [$business, , ,] = $this->redeemedVisit();
        [, $qrToken] = World::qr($business);
        $this->enrollAs($business, $qrToken, 'Fri', 'End', '+12125550777');
        $this->assertStringContainsString('the same way your friends can find it through you', $this->shareHtml());

        $this->pauseBusiness($business->fresh());
        $html = $this->shareHtml();
        $this->assertStringNotContainsString('the same way your friends can find it through you', $html);

        // Closed.
        [$business2, , ,] = $this->redeemedVisit();
        [, $qrToken2] = World::qr($business2);
        $this->enrollAs($business2, $qrToken2, 'Fri', 'End', '+12125550777');
        $this->assertStringContainsString('the same way your friends can find it through you', $this->shareHtml());

        $business2->forceFill(['status' => 'closed'])->save();
        $html2 = $this->shareHtml();
        $this->assertStringNotContainsString('the same way your friends can find it through you', $html2);
    }

    // ---------------------------------------------------- absence pins + outsider

    public function test_the_reminder_block_carries_no_deadline_no_reward_amount_and_no_referrer_details(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        World::membership($business, $friend);
        World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $this->enrollAs($business, $qrToken, 'Fri', 'End', '+12125550777');
        $html = $this->shareHtml();

        $start = strpos($html, 'You found '.$business->name.' through Reg');
        $this->assertNotFalse($start, 'the reminder line renders');
        $lineHtml = substr($html, $start, strpos($html, '</p>', $start) - $start);

        $this->assertStringNotContainsString('$', $lineHtml, 'the line names no reward amount; the prospective line below owns that truth');
        $this->assertStringNotContainsString('Ular', $lineHtml, 'first name only');
        $this->assertStringNotContainsString('2125550999', $lineHtml);
        foreach (['14 days', 'within', 'deadline', 'too late', 'hurry', 'generation'] as $banned) {
            $this->assertStringNotContainsString($banned, $lineHtml, "no '{$banned}' wording in the reminder");
        }
    }

    public function test_an_outsider_without_a_verified_session_cannot_reach_the_share_page(): void
    {
        $response = $this->get(route('refer.share'));
        $response->assertRedirect();
        $this->assertStringNotContainsString(
            'the same way your friends can find it through you',
            $response->getContent(),
        );
    }

    // ---------------------------------------------------- gaming: the gap

    public function test_a_companion_claiming_inside_the_friends_gap_completes_the_claim_but_cannot_redeem_yet(): void
    {
        // The friend's membership is FRESH (created moments
        // ago, as at their own claim completion): a companion
        // beside them taps the friend's link and claims. Per
        // the 2026-10-29 ruling the claim completes at once;
        // the 25-minute gap refuses at REDEMPTION only, anchored
        // on the friend's membership created_at, and ID-08's
        // surfaces neither shorten nor restart it.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership] = World::membership($business, $friend);
        World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        // World::referral ages its REFERRER (Reg) only; the
        // friend's own anchor stays fresh for this test.
        $this->assertTrue($friendMembership->fresh()->created_at->greaterThan(now()->subMinutes(5)));

        $done = app(EnrollmentService::class)->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Com', 'last_name' => 'Panion',
            'phone_e164' => '+12125550555', 'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('created', $done['kind']);

        $result = app(RedemptionService::class)->redeemReferral($done['referral'], null, '127.0.0.1');
        $this->assertSame('too_early', $result['outcome']);
        $this->assertSame(Referral::STATE_PENDING, $done['referral']->fresh()->state);
        $this->assertSame(0, Reward::query()->where('membership_id', $friendMembership->id)->count());
    }

    // ---------------------------------------------------- MEASURE (Section 7.4)

    public function test_the_measure_query_counts_a_downstream_referral_inside_14_days_and_not_at_day_15(): void
    {
        $business = World::business();

        // Friend one: redeemed 20 days ago; their downstream
        // referral was created 13 days after that redemption.
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership] = World::membership($business, $friend);
        [$r1] = World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now()->subDays(20),
        ]);
        $r1->forceFill(['created_at' => now()->subDays(21)])->save();
        $downstreamFriend = World::customer('+12125550321', 'Thi', 'Rd');
        [$r2] = World::referral($friendMembership, $downstreamFriend, ['state' => Referral::STATE_PENDING]);
        $r2->forceFill(['created_at' => $r1->fresh()->redeemed_at->copy()->addDays(13)])->save();

        // Friend two (the day-15 control): redeemed at the same
        // moment; their downstream referral lands at day 15.
        $friend2 = World::customer('+12125550666', 'Sec', 'Ond');
        [$friend2Membership] = World::membership($business, $friend2);
        [$r1b] = World::referral($referrerMembership, $friend2, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now()->subDays(20),
        ]);
        $r1b->forceFill(['created_at' => now()->subDays(21)])->save();
        $downstreamFriend2 = World::customer('+12125550444', 'Fou', 'Rth');
        [$r2b] = World::referral($friend2Membership, $downstreamFriend2, ['state' => Referral::STATE_PENDING]);
        $r2b->forceFill(['created_at' => $r1b->fresh()->redeemed_at->copy()->addDays(15)])->save();

        // The worked example: cohort 2, activated 1, rate 50%.
        $cohort = [$r1->fresh(), $r1b->fresh()];
        $activated = array_filter($cohort, fn (Referral $r) => $this->activatedWithin14Days($r));
        $this->assertTrue($this->activatedWithin14Days($r1->fresh()), 'a downstream referral at day 13 counts');
        $this->assertFalse($this->activatedWithin14Days($r1b->fresh()), 'a downstream referral at day 15 does not count');
        $this->assertCount(1, $activated);
        $this->assertSame(50.0, count($activated) / count($cohort) * 100);
    }

    // ---------------------------------------------------- OD-2 concordance (pin 7)

    public function test_od2_concordance_with_the_referrer_text_blank_across_prime_share_and_the_minted_reward(): void
    {
        [$business, , $redeemToken, $friendMembership] = $this->redeemedVisit(['referrer_reward_text' => null]);

        // The receipt prime reads the friend text for the
        // referrer side.
        $receipt = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('and you get $10 off your first visit when they visit.', $receipt);

        // The share page's prospective line reads the same text.
        [, $qrToken] = World::qr($business);
        $this->enrollAs($business, $qrToken, 'Fri', 'End', '+12125550777');
        $share = $this->shareHtml();
        $this->assertStringContainsString(
            'When a friend visits '.$business->name.' with your link, you get $10 off your first visit',
            $share,
        );

        // And the reward that actually mints for the friend as
        // referrer snapshots that same text. The friend's
        // membership is aged past the 25-minute redemption gap
        // first (the normal ahead-of-the-visit share).
        $friendMembership->forceFill(['created_at' => now()->subDays(2)])->save();
        $done = app(EnrollmentService::class)->completeClaim($friendMembership->refresh(), [
            'first_name' => 'New', 'last_name' => 'Pal',
            'phone_e164' => '+12125550444', 'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('created', $done['kind']);

        $result = app(RedemptionService::class)->redeemReferral($done['referral'], null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $minted = Reward::query()->where('membership_id', $friendMembership->id)->firstOrFail();
        $this->assertSame('$10 off your first visit', $minted->reward_text_snapshot);
    }

    // ---------------------------------------------------- friction, Path A (Section 13)

    public function test_the_receipt_prime_offers_the_friends_own_code_with_zero_setup_steps(): void
    {
        // Path A budget: own code live = 0 taps, 0 decisions, 0
        // typed fields. The proof is structural: the ONE receipt
        // render already carries the friend's own share URL and
        // the share actions; no enrollment, activation, or
        // code-generation request exists between the receipt
        // and a live code.
        [, , $redeemToken, , $friendShareToken] = $this->redeemedVisit();

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();

        $this->assertStringContainsString(route('refer.friend', ['token' => $friendShareToken]), $html);
        $this->assertStringContainsString('Share your link', $html);
    }
}
