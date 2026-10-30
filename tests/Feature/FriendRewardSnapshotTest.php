<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * F-UP-1 (truth sweep 2026-10-29 PM, Known Item 1): the friend's
 * reward text is snapshotted at CLAIM CREATION onto the referral
 * (referrals.friend_reward_text_snapshot), from the same source
 * the claim flow renders at that moment (Business::rewardText(),
 * the trimmed friend_reward_text the friend page's "Claim {text}"
 * line is built from). Historical claims (redeemed / expired /
 * voided) and the counter handover surfaces render the snapshot;
 * in-flight (pending) record surfaces keep rendering live config.
 * Rows that predate the column (snapshot NULL) fall back to live
 * config: the LEGACY LIVE-CONFIG FALLBACK, named in
 * Referral::promisedFriendRewardText() and in
 * ReferralRewardLines::friend().
 *
 * TWO-KEY FLAG: every assertion here about WHAT a reward line
 * renders is a reward-truth hunk for the two-key reviewer.
 */
class FriendRewardSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    /** A referrer old enough that the 25-minute claim gap has run. */
    private function referrer(Business $business, string $phone = '+12125550901'): Membership
    {
        [$membership] = World::membership($business, World::customer($phone, 'Reg', 'Ular'));
        $membership->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        return $membership->refresh();
    }

    /** Create a real claim through the claim-completion service. */
    private function claim(Membership $referrer, string $phone, string $first = 'Jo'): Referral
    {
        $done = app(EnrollmentService::class)->completeClaim($referrer, [
            'first_name' => $first,
            'last_name' => 'Kim',
            'phone_e164' => $phone,
            'email' => null,
            'consent' => true,
        ], '127.0.0.1', null);

        $this->assertSame('created', $done['kind']);

        return $done['referral'];
    }

    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    public function test_claim_creation_snapshots_the_promised_friend_reward_text(): void
    {
        $business = World::business(); // friend text: $10 off your first visit
        $referral = $this->claim($this->referrer($business), '+12125550212');

        $this->assertSame('$10 off your first visit', $referral->fresh()->friend_reward_text_snapshot);
        $this->assertTrue(Schema::hasColumn('referrals', 'friend_reward_text_snapshot'));
    }

    public function test_edit_after_claim_redeemed_history_shows_the_original_on_every_census_surface(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referral = $this->claim($this->referrer($business), '+12125550213');
        $token = $referral->redeem_token_encrypted;

        // The owner edits BOTH texts after the claim was made.
        $business->setRewardConfiguration('Free coffee tomorrow', true, 'Referrer prize B');

        // (a) Staff confirm screen: the handover instruction is the
        // claim-time promise, not the edited live text.
        $confirm = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $confirm);
        $this->assertStringNotContainsString('Free coffee tomorrow', $confirm);

        // (b) The confirm press itself: redemption completes, and
        // the money path is byte-unaffected by the edit: one
        // billing event at the plan price, one referrer reward
        // minted from the referrer resolver at redemption (the
        // referrer side's existing live-at-mint rule, untouched).
        $nonce = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $token]));

        $referral->refresh();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->state);
        $this->assertSame('$10 off your first visit', $referral->friend_reward_text_snapshot);
        $billing = BillingEvent::query()->where('referral_id', $referral->id)->get();
        $this->assertCount(1, $billing);
        $this->assertSame(200, $billing[0]->amount_cents);
        $reward = Reward::query()->where('referral_id', $referral->id)->sole();
        $this->assertSame('Referrer prize B', $reward->reward_text_snapshot);

        // (c) Staff success flood (the landing of the press, and
        // the follow GET that renders it): the snapshot text.
        $flood = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $flood);
        $this->assertStringNotContainsString('Free coffee tomorrow', $flood);

        // (d) Staff already-redeemed state page (a later re-scan,
        // after the one-shot success flash is spent).
        $again = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('Already redeemed', $again);
        $this->assertStringContainsString('$10 off your first visit', $again);
        $this->assertStringNotContainsString('Free coffee tomorrow', $again);

        // (e) The friend's own redeemed receipt: the flood
        // headline is the snapshot. The page-wide absence check
        // does NOT apply here by design: the RS-N2 prime below
        // the receipt speaks about the friend's FUTURE referrals
        // and correctly composes its share message from LIVE
        // config, so the edited text appears there, as a
        // prospective offer only (both directions pinned).
        $receipt = $this->get(route('refer.redeem.page', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $receipt);
        $this->assertStringContainsString('invited you to get your Reward: Free coffee tomorrow', $receipt);

        // (f) Owner referral detail, the MEDIUM core: the original
        // text sits beside "Given at the visit on {date}".
        $detail = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $detail);
        $this->assertStringContainsString('Given at the visit on ', $detail);
        $this->assertStringNotContainsString('Free coffee tomorrow', $detail);
    }

    public function test_fresh_in_flight_claim_renders_live_config_until_it_completes(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->referrer($business);

        $business->setRewardConfiguration('Free coffee tomorrow', true, 'Referrer prize B');
        $pending = $this->claim($referrer, '+12125550214');
        // A fresh claim snapshots the NEW (current) text...
        $this->assertSame('Free coffee tomorrow', $pending->fresh()->friend_reward_text_snapshot);

        // ...and while it is in flight, a further edit moves its
        // pending record surfaces to the live text (the snapshot
        // governs only completed / expired / voided history).
        $business->setRewardConfiguration('Half-price smoothie', true, 'Referrer prize C');
        $detail = $this->actingAs($owner)->get(route('referrals.show', $pending))->assertOk()->getContent();
        $this->assertStringContainsString('Half-price smoothie', $detail);
        $this->assertStringContainsString('Given when they visit.', $detail);
    }

    public function test_expired_claim_shows_its_snapshot_on_staff_expired_state_and_owner_detail(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referral = $this->claim($this->referrer($business), '+12125550215');
        $token = $referral->redeem_token_encrypted;

        $business->setRewardConfiguration('Half-price smoothie', true, 'Referrer prize C');
        $referral->forceFill(['expires_at' => now()->subDay()])->save();

        // Staff expired state (the render flips it to expired).
        $staff = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('This reward expired on', $staff);
        $this->assertStringContainsString('$10 off your first visit', $staff);
        $this->assertStringNotContainsString('Half-price smoothie', $staff);

        $this->assertSame(Referral::STATE_EXPIRED, $referral->fresh()->state);

        // Owner detail expired row.
        $detail = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $detail);
        $this->assertStringContainsString('Not given. The visit window has ended.', $detail);
        $this->assertStringNotContainsString('Half-price smoothie', $detail);
    }

    public function test_voided_claim_shows_its_snapshot_on_owner_detail(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referral = $this->claim($this->referrer($business), '+12125550216');

        $business->setRewardConfiguration('Half-price smoothie', true, 'Referrer prize C');
        $referral->forceFill(['state' => Referral::STATE_VOIDED])->save();

        $detail = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $detail);
        $this->assertStringContainsString('Not given.', $detail);
        $this->assertStringNotContainsString('Half-price smoothie', $detail);
    }

    public function test_legacy_redeemed_row_without_snapshot_falls_back_to_live_config(): void
    {
        // A row that predates the snapshot column: no snapshot was
        // ever saved, so the LEGACY LIVE-CONFIG FALLBACK renders
        // the live text (there is no other truth for it).
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550221', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550222', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now()->subDay(),
        ]);
        $this->assertNull($referral->friend_reward_text_snapshot);

        $detail = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertStringContainsString('$10 off your first visit', $detail);
        $this->assertStringContainsString('Given at the visit on ', $detail);
    }

    public function test_pending_redeem_page_and_staff_confirm_both_show_claim_time_text_after_mid_flight_edit(): void
    {
        // F-UP-1 two-key Finding 6 pin (both directions): claim
        // under reward text A, owner edits the reward to B
        // mid-flight, and the two counter surfaces for the SAME
        // pending referral must agree on A — the friend's pending
        // redeem page (composed headline, with the first-visit
        // suffix A itself earns) and the staff confirm headline.
        // Neither may show B.
        $business = World::business();
        $business->setRewardConfiguration('Free pastry', true, 'Referrer prize A');
        $referral = $this->claim($this->referrer($business), '+12125550217');
        $this->assertSame('Free pastry', $referral->fresh()->friend_reward_text_snapshot);
        $token = $referral->redeem_token_encrypted;

        $business->setRewardConfiguration('Half-price smoothie', true, 'Referrer prize B');

        // Friend pending redeem page ("show" state): A composed
        // with its first-visit suffix, not B.
        $friend = $this->get(route('refer.redeem.page', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('Free pastry on your first visit', $friend);
        $this->assertStringNotContainsString('Half-price smoothie', $friend);

        // Staff confirm: the same A, not B.
        $confirm = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->getContent();
        $this->assertStringContainsString('Free pastry', $confirm);
        $this->assertStringNotContainsString('Half-price smoothie', $confirm);
    }

    public function test_snapshot_column_has_exactly_one_writer_in_app_code(): void
    {
        // The claim-creation write in EnrollmentService plus the
        // model's own fillable/helper references are the only
        // places app code may name the column (resolver discipline:
        // every reader goes through the Referral helpers, so no
        // controller or blade names the column directly).
        $allowed = [
            'app/Models/Referral.php',
            'app/Services/Referral/EnrollmentService.php',
        ];
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains(file_get_contents($file->getPathname()), 'friend_reward_text_snapshot')) {
                $hits[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        sort($hits);
        $this->assertSame($allowed, $hits);
    }
}
