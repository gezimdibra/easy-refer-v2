<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\ReferralEvent;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Referrals and Rewards lists (spec Sections 5.3 and 5.4,
 * Sprint 3): filters and counts, scoping, the detail timeline,
 * regenerate-redeem-link semantics, and the reward notification
 * retry rule.
 */
class ReferralListsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(\App\Contracts\SmsGateway::class, new \Tests\Fakes\FakeSmsGateway);
    }

    public function test_referral_filters_and_counts(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550301', 'Ana', 'Ray'));

        World::referral($membership, World::customer('+12125550311', 'Penny', 'Pending'));
        World::referral($membership, World::customer('+12125550312', 'Rita', 'Redeemed'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::referral($membership, World::customer('+12125550313', 'Erin', 'Expired'), [
            'state' => Referral::STATE_EXPIRED,
        ]);
        World::referral($membership, World::customer('+12125550314', 'Hank', 'Held'), [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $all = $this->actingAs($owner)->get(route('referrals.index'));
        $all->assertOk();
        $all->assertSee('Penny');
        $all->assertSee('Rita');
        $all->assertSee('Held for review:');
        $all->assertSee('Open the review queue');

        $this->actingAs($owner)->get(route('referrals.index', ['filter' => 'pending']))
            ->assertSee('Penny')->assertDontSee('Rita');
        $this->actingAs($owner)->get(route('referrals.index', ['filter' => 'held']))
            ->assertSee('Hank')->assertDontSee('Penny');
        $this->actingAs($owner)->get(route('referrals.index', ['filter' => 'bogus']))
            ->assertSee('Penny');
    }

    public function test_referral_lists_are_scoped_to_the_business(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $other = World::business();
        [$foreignMembership] = World::membership($other, World::customer('+12125550321', 'Ana', 'Ray'));
        [$foreign] = World::referral($foreignMembership, World::customer('+12125550322', 'Fay', 'One'));

        $this->actingAs($owner)->get(route('referrals.index'))->assertDontSee('Fay');
        $this->actingAs($owner)->get(route('referrals.show', $foreign))->assertNotFound();
        $this->actingAs($owner)->post(route('referrals.regenerate', $foreign))->assertNotFound();
    }

    public function test_regenerate_kills_the_old_token_and_writes_event_and_audit(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550331', 'Ana', 'Ray'));
        [$referral, $oldPlain] = World::referral($membership, World::customer('+12125550332', 'Fay', 'One'));

        ReferralEvent::record('referral_created', 'customer', $business, $referral, null, $membership);

        // The original link went out by text (the ledger is the
        // record), so the new one is texted too and the outcome
        // says so.
        \App\Models\SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $referral->referred_customer_id,
            'phone_e164' => '+12125550332',
            'rendered_body' => 'link',
            'purpose' => 'friend_link',
            'template_key' => 'friend_link',
            'delivery_state' => 'delivered',
            'consent_basis' => 'transactional',
            'opt_out_state_at_send' => false,
        ]);

        $this->actingAs($owner)->post(route('referrals.regenerate', $referral))
            ->assertSessionHas('status', 'New redeem link sent. The old link no longer works.');

        $fresh = $referral->fresh();
        $this->assertNotSame(\App\Support\OpaqueToken::hash($oldPlain), $fresh->redeem_token_hash);
        $this->assertSame(Referral::STATE_PENDING, $fresh->state);
        $this->assertTrue(ReferralEvent::query()
            ->where('referral_id', $referral->id)
            ->where('event_type', 'redeem_token_regenerated')
            ->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'referral.redeem_link_regenerated')->exists());

        // The old token resolves to the generic error; the new
        // one reaches the redeem page.
        $this->get(route('refer.redeem.page', ['token' => $oldPlain]))->assertNotFound();
        $this->get(route('refer.redeem.page', ['token' => $fresh->redeem_token_encrypted]))->assertOk();

        // The detail timeline carries the event under its label.
        $this->actingAs($owner)->get(route('referrals.show', $referral))
            ->assertSee('What happened')
            ->assertSee('New redeem link sent')
            ->assertSee('Started');
    }

    public function test_detail_timeline_times_render_in_the_business_timezone(): void
    {
        // T2-F4: the timeline rendered UTC times with no zone
        // label: a redemption at 1:43 AM local read "5:43 AM" to
        // the owner. Owner-facing times now render in the
        // business's timezone (businesses.timezone).
        $business = World::business(['timezone' => 'America/New_York']);
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550371', 'Ana', 'Ray'));
        [$referral] = World::referral($membership, World::customer('+12125550372', 'Rob', 'Dee'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $event = new ReferralEvent;
        $event->forceFill([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'event_type' => 'redeem_token_regenerated',
            'actor' => 'owner',
            'created_at' => \Illuminate\Support\Carbon::parse('2026-10-25 05:43:00', 'UTC'),
        ])->save();

        $this->actingAs($owner)->get(route('referrals.show', $referral))
            ->assertOk()
            ->assertSee('Oct 25, 2026, 1:43 AM')
            ->assertDontSee('5:43 AM');
    }

    public function test_regenerate_without_a_prior_text_shows_the_link_on_the_detail_page(): void
    {
        // Spec verifier finding 3: when no text ever carried the
        // link, claiming one was "sent" would strand the referral.
        // The outcome is honest and the detail page carries the
        // current link for the handover.
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550361', 'Ana', 'Ray'));
        [$referral, $oldPlain] = World::referral($membership, World::customer('+12125550362', 'Fay', 'One'));

        $this->actingAs($owner)->post(route('referrals.regenerate', $referral))
            ->assertSessionHas('status', 'New redeem link created. The old link no longer works. Share the new link with your customer from this page.');

        $fresh = $referral->fresh();
        $newUrl = route('refer.redeem.page', ['token' => $fresh->redeem_token_encrypted]);

        $this->actingAs($owner)->get(route('referrals.show', $referral))
            ->assertOk()
            ->assertSee('Redeem link')
            ->assertSee($newUrl);
        $this->get(route('refer.redeem.page', ['token' => $oldPlain]))->assertNotFound();
        $this->get(route('refer.redeem.page', ['token' => $fresh->redeem_token_encrypted]))->assertOk();

        // No friend_link text was manufactured by the regenerate.
        $this->assertFalse(\App\Models\SmsMessage::query()->where('purpose', 'friend_link')->exists());
    }

    public function test_lists_apply_expiry_and_vesting_on_read(): void
    {
        // Spec verifier finding 2: counts and filters read
        // post-transition state without any sweeper running.
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550371', 'Ana', 'Ray'));
        World::referral($membership, World::customer('+12125550372', 'Erin', 'Stale'), [
            'expires_at' => now()->subHour(),
        ]);
        [$redeemed] = World::referral($membership, World::customer('+12125550373', 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $redeemed, [
            'state' => \App\Models\Reward::STATE_PENDING_VESTING, 'available_at' => now()->subHour(),
        ]);

        $referrals = $this->actingAs($owner)->get(route('referrals.index'));
        $counts = $referrals->viewData('counts');
        $this->assertSame(0, $counts['pending']);
        $this->assertSame(1, $counts['expired']);
        $this->actingAs($owner)->get(route('referrals.index', ['filter' => 'expired']))->assertSee('Erin');
        $this->actingAs($owner)->get(route('referrals.index', ['filter' => 'pending']))->assertDontSee('Erin');

        $rewards = $this->actingAs($owner)->get(route('rewards.index'));
        $rewardCounts = $rewards->viewData('counts');
        $this->assertSame(0, $rewardCounts['pending_vesting']);
        $this->assertSame(1, $rewardCounts['available']);
    }

    public function test_regenerate_refuses_a_completed_referral(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550341', 'Ana', 'Ray'));
        [$referral] = World::referral($membership, World::customer('+12125550342', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('referrals.regenerate', $referral))
            ->assertSessionHasErrors('referral');
    }

    public function test_reward_filter_counts_form_a_partition_and_held_lives_only_under_held(): void
    {
        // T5-F2: the tabs are presented as a partition of All,
        // but a held reward matched both its state filter and
        // Held, so the counts summed past All and the held row
        // appeared under "On its way" too.
        $business = World::business();
        $owner = World::owner($business);

        $mk = function (string $phone, string $name, array $rewardOverrides = []) use ($business) {
            [$membership] = World::membership($business, World::customer($phone, $name, 'Test'));
            [$referral] = World::referral($membership, World::customer($phone.'9', 'Friend', 'Test'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
            ]);
            World::reward($membership, $referral, $rewardOverrides);
        };

        $mk('+12125550401', 'Hilda', [
            'state' => \App\Models\Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(7),
            'hold' => true,
            'hold_rules' => ['velocity'],
            'held_at' => now(),
        ]);
        $mk('+12125550402', 'Vera', [
            'state' => \App\Models\Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(7),
        ]);
        $mk('+12125550403', 'Alicia', ['state' => \App\Models\Reward::STATE_AVAILABLE]);
        $mk('+12125550404', 'Clara', [
            'state' => \App\Models\Reward::STATE_CLAIMED, 'claimed_at' => now()->subDay(),
        ]);

        $counts = $this->actingAs($owner)->get(route('rewards.index'))->viewData('counts');
        $this->assertSame(4, $counts['all']);
        $this->assertSame(1, $counts['pending_vesting']);
        $this->assertSame(1, $counts['available']);
        $this->assertSame(1, $counts['claimed']);
        $this->assertSame(1, $counts['held']);
        $this->assertSame(
            $counts['all'],
            $counts['pending_vesting'] + $counts['available'] + $counts['claimed'] + $counts['held'],
        );

        $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'pending_vesting']))
            ->assertSee('Vera')->assertDontSee('Hilda');
        $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'held']))
            ->assertSee('Hilda')->assertDontSee('Vera');
        $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'available']))
            ->assertSee('Alicia')->assertDontSee('Hilda');
        $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'claimed']))
            ->assertSee('Clara')->assertDontSee('Hilda');
    }

    public function test_held_reward_past_its_vesting_date_stays_only_under_held(): void
    {
        // The other overlap: vesting flips state on read without
        // consulting the hold, so a held reward whose date has
        // passed becomes "available" in state while still held.
        // It must not leak into Ready to claim.
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550411', 'Hilda', 'Test'));
        [$referral] = World::referral($membership, World::customer('+12125550412', 'Friend', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(10),
        ]);
        World::reward($membership, $referral, [
            'state' => \App\Models\Reward::STATE_PENDING_VESTING,
            'available_at' => now()->subDay(),
            'hold' => true,
            'hold_rules' => ['velocity'],
            'held_at' => now()->subDays(9),
        ]);

        $counts = $this->actingAs($owner)->get(route('rewards.index'))->viewData('counts');
        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['held']);
        $this->assertSame(0, $counts['available']);
        $this->assertSame(0, $counts['pending_vesting']);

        $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'available']))
            ->assertDontSee('Hilda');
        $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'held']))
            ->assertSee('Hilda');
    }

    public function test_rewards_list_filters_snapshot_text_and_resend_rule(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550351', 'Ana', 'Ray'));
        [$r1] = World::referral($membership, World::customer('+12125550352', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$available] = World::reward($membership, $r1, ['reward_text_snapshot' => 'Snapshot text A']);
        [$r2] = World::referral($membership, World::customer('+12125550353', 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$failed] = World::reward($membership, $r2, [
            'reward_text_snapshot' => 'Snapshot text B',
            'notification_state' => 'failed',
        ]);

        // Change the settings text afterwards: the list must keep
        // showing the mint-time snapshot, never the new text.
        $business->forceFill(['friend_reward_text' => 'Brand new settings text'])->save();

        $response = $this->actingAs($owner)->get(route('rewards.index'));
        $response->assertOk();
        $response->assertSee('Snapshot text A');
        $response->assertSee('Snapshot text B');
        $response->assertDontSee('Brand new settings text');
        $response->assertSee('Text again');

        // A sent notification cannot be re-sent; a failed one can.
        $this->actingAs($owner)->post(route('rewards.resend', $available))
            ->assertSessionHasErrors('reward');
        $this->actingAs($owner)->post(route('rewards.resend', $failed))
            ->assertSessionHas('status', 'Text sent again.');
        $this->assertTrue(AuditLog::query()->where('action', 'reward.notification_resent')->exists());
    }
}
