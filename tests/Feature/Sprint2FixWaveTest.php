<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Membership;
use App\Models\ReferralEvent;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Sprint 2 fix wave regressions: the designed outcomes the UI and
 * Tester waves found missing (F-08, F-10, F-11, F-16, F-22,
 * F-25, F-27), pinned so they cannot silently regress. The F-01
 * class-to-style closure has its own suite
 * (ButtonStyleClosureTest); the error-system items are pinned in
 * LoginTest, AuthCoverageTest, RegistrationTest, and ReferFlowTest.
 * The status-flash single-render rule (re-verification nit N-1) is
 * pinned at the end of this file.
 */
class Sprint2FixWaveTest extends TestCase
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
        $this->app['session']->flush();
        $this->withUnencryptedCookie('er2_flow', '');
    }

    private function enrollCustomer(string $qrToken, string $phone = '+12125550111', string $first = 'Pat'): void
    {
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());

        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => $first,
            'last_name' => 'Lee',
            'phone' => $phone,
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));
    }

    public function test_staff_referral_confirmation_follows_the_design_structure(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend);

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            // The reward is the headline, not a prefixed line.
            ->assertSee('$10 off your first visit')
            ->assertSee('For Fri')
            // R4-U3: the verdict band and the facts well (Business /
            // Sent by) replace the old kind line.
            ->assertSee('Ready to redeem')
            ->assertSee('Sent by')
            ->assertSee('Not now')
            ->assertDontSee('First visit reward')
            ->assertDontSee('Reward: $10')
            ->assertDontSee('Hand the customer their reward');
    }

    public function test_staff_reward_confirmation_names_the_reward_kind(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        [, $claimToken] = World::reward($membership, $referral);

        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('$10 off')
            ->assertSee('For Reg')
            // R4-U3: the kind line ("Reg's reward") is retired; the
            // band names the action and the facts well names the
            // sender.
            ->assertSee('Ready to claim')
            ->assertSee('Sent by')
            ->assertSee('Not now')
            ->assertDontSee("Reg's reward", false)
            ->assertDontSee('Hand the customer their reward');
    }

    public function test_qr_page_posters_facts_and_regenerate(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$qr, $qrToken] = World::qr($business);

        // QRK-07 (Train 3, Amendment 23 / REF-POSTER-03): the
        // "About this code" panel and its single-code copy are
        // retired; the placements panel carries the counter
        // code's added date and the per-placement replace modal
        // copy, and the danger zone replaces all codes at once.
        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            // P3 MIX: the placements table and its size labels
            // are retired; the by-spot panel names each spot and
            // its print action, and the Added date is gone
            // (QrPlacementsPanelTest pins the new grammar).
            ->assertSee('Print by spot')
            // [MIX-04] (Amendment 31): the counter spot line
            // carries its real print size.
            ->assertSee('By the register, where people pay. Prints on half a sheet of paper (5.8 x 8.3 in).')
            ->assertSee('Print the counter poster')
            // [ON-QR-1] (Amendment 31): the Screen poster is retired.
            ->assertDontSee('Screen poster')
            ->assertDontSee("This is your store's only active code", false)
            ->assertDontSee('Your codes by placement')
            // QF-5 (Amendment 29 [QF-2]): the redemption
            // paragraph is now the numbered three-step list;
            // the steps are pinned in full in QrFrameTest.
            ->assertSee('A customer shows their reward code.')
            ->assertSee('A staff member scans it with their own phone.')
            ->assertSee('They tap Mark as Redeemed.')
            ->assertSee('No app, no login, nothing to type.')
            // [ON-SPOT-3] (Amendment 31): the replace modal
            // title is shared and its body reads the current
            // display name (the counter default here).
            ->assertSee('Replace this code?')
            ->assertSee('Printed copies of the Counter poster code will stop working right away. Your other codes keep working.')
            ->assertSee('Every printed code for your store will stop working right away. Print new posters for each spot after you replace them.');

        // A poster renders as its own printable document; a
        // print-mode open (the URL every poster link carries)
        // records the download the checklist reads. A bare view
        // records nothing (coherence Phase 2, ruling Q2; pinned
        // by PosterPrintCompletionTest).
        $this->actingAs($owner)->get(route('owner.qr.poster', ['counter', 'print' => 1]))
            ->assertOk()
            ->assertSee($business->name)
            // Amendment 58 [REF-POSTER-04]: the print-mode open
            // carries the composed poster headline (asserted as
            // text: the F5 amount span interrupts it in markup).
            ->assertSeeText('Give a friend $10 off your first visit. Get $10 off when they visit.');
        $this->assertSame(1, ReferralEvent::query()->where('event_type', 'poster_downloaded')->count());

        $this->actingAs($owner)->get(route('owner.qr.poster', 'bogus'))->assertNotFound();

        // Replacing (now: all codes at once, REF-POSTER-03c)
        // retires the printed code right away.
        $this->actingAs($owner)->post(route('owner.qr.regenerate'))
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', 'All new codes are ready. Print new posters to replace the old ones.');

        $this->assertSame('revoked', $qr->fresh()->state);
        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertNotFound()
            ->assertSee('This code is not active');

        $new = $business->fresh()->activeQrCode();
        $this->assertNotNull($new);
        $this->assertNotSame($qr->id, $new->id);
        $this->get(route('refer.landing', ['token' => $new->plainToken()]))->assertOk();
    }

    /**
     * N-1: a flashed status renders exactly once. The layout
     * notices partial is the single render point; the QR and
     * Reviews views each rendered session('status') a second
     * time inline, so every flash on those pages showed twice,
     * and the verify-email sentinel leaked through the partial
     * as raw machine text beside the view's own sentence.
     */
    public function test_status_flash_renders_exactly_once_on_owner_pages(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $message = 'All new codes are ready. Print new posters to replace the old ones.';
        $this->actingAs($owner)->post(route('owner.qr.regenerate'))
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', $message);

        $qrPage = $this->actingAs($owner)->get(route('owner.qr'))->assertOk();
        $this->assertSame(1, substr_count($qrPage->getContent(), $message),
            'The regeneration notice must render exactly once on the QR page.');

        $reviews = $this->actingAs($owner)
            ->withSession(['status' => 'Referral released.'])
            ->get(route('reviews'))
            ->assertOk();
        $this->assertSame(1, substr_count($reviews->getContent(), 'Referral released.'),
            'A flashed status must render exactly once on the Reviews page.');

        $verify = $this->actingAs($owner)
            ->withSession(['status' => 'verification-link-sent'])
            ->get(route('verification.notice'))
            ->assertOk();
        $this->assertStringNotContainsString('verification-link-sent', $verify->getContent(),
            'The sentinel is a machine key, never banner text.');
        $this->assertSame(1, substr_count($verify->getContent(), 'A new verification link is on its way to your email address.'));
    }

    public function test_share_page_for_a_new_member(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($qrToken);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('You are in, Pat')
            ->assertSee('When a friend visits '.$business->name.' with your link, you get $10 off')
            ->assertSee('Share your link')
            ->assertSee('Show this to a friend in person')
            ->assertSee('Your link stays the same. Bookmark this page to find it again.')
            // The hand-built channel grid and the garbled friend
            // line are gone (F-16).
            ->assertDontSee('wa.me')
            ->assertDontSee('Your friend gets');
    }

    public function test_share_page_greets_a_returning_member(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($qrToken);
        $this->flushSessionAndCookies();

        // The same phone enrolling again is a returning member.
        $this->enrollCustomer($qrToken);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Welcome back, Pat')
            ->assertDontSee('You are in, Pat');
    }

    public function test_self_claim_points_to_the_referrers_own_share_page(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($qrToken);
        $membership = Membership::query()->firstOrFail();
        $this->flushSessionAndCookies();

        // SELF-01 refuses the referrer's own number at the
        // claim step now, so this F-27 test reaches the
        // completion backstop the crafted way: a claim starts
        // with a different number, then the server-side pending
        // is rewritten to the referrer's own, exactly what a
        // tampered client would present at completion.
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $membership->plainShareToken()]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550999', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $flowSession = \App\Models\FlowSession::query()->get()
            ->first(fn (\App\Models\FlowSession $s) => ($s->data['pending']['kind'] ?? null) === 'claim');
        $this->assertNotNull($flowSession);
        $pendingData = $flowSession->data;
        $pendingData['pending']['phone_e164'] = '+12125550111';
        $flowSession->data = $pendingData;
        $flowSession->save();

        $verify = $this->post(route('refer.verify.check'), ['code' => '123456']);
        $verify->assertOk()
            ->assertSee('You cannot refer yourself')
            ->assertSee('This is your own referral link. Share it with a friend to earn your reward.')
            ->assertSee('Share your link')
            ->assertDontSee('Use a different number');

        // The offered way forward resolves: the flow was verified
        // as the referrer, so their share page loads.
        $this->carryFlowCookie($verify);
        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Welcome back, Pat');
    }

    public function test_value_panel_shows_the_all_time_line_when_earlier_months_exist(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$first] = World::referral($membership, $friend);
        app(RedemptionService::class)->redeemReferral($first, null, '127.0.0.1');

        $this->travel(40)->days();

        $friend2 = World::customer('+12125550666', 'Fra', 'Nk');
        [$second] = World::referral($membership, $friend2);
        app(RedemptionService::class)->redeemReferral($second, null, '127.0.0.1');

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 visit counted this month')
            ->assertSee('All time: 2 visits counted, about $100.00 in estimated sales, based on your typical spend per visit (estimate)');
    }

    public function test_customer_receipts_render_the_success_flood(): void
    {
        // F-02 for the customer side: the redeemed and claimed
        // receipts are the Section 3.5 flood with REF-CLAIM-03's
        // receipt line, not a plain status line.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral, $redeemToken] = World::referral($membership, $friend, [
            'state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $this->get(route('refer.redeem.page', ['token' => $redeemToken]))
            ->assertOk()
            ->assertSee('Keep this screen as your receipt.')
            // R4-U4: "Confirmed" is now the fact row's label.
            ->assertSee('Confirmed')
            ->assertSee('Reward redeemed')
            ->assertSee('$10 off your first visit');

        [, $claimToken] = World::reward($membership, $referral, [
            'state' => \App\Models\Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Keep this screen as your receipt.')
            ->assertSee('Confirmed')
            ->assertSee('Reward claimed');
    }

    public function test_vertical_pages_render_no_site_footer(): void
    {
        $this->get('/for-barbers')
            ->assertOk()
            ->assertDontSee('<footer', false);

        // The full-chrome home page keeps its footer.
        World::business();
        $this->get('/')->assertOk()->assertSee('<footer', false);
    }

    public function test_calculator_results_are_server_rendered(): void
    {
        World::business();

        $this->get('/')
            ->assertOk()
            ->assertSee('x-text="customers">22', false)
            ->assertSee('x-text="fee">44', false)
            ->assertSee('x-text="value">880', false);
    }
}
