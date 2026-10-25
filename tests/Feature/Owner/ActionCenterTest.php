<?php

namespace Tests\Feature\Owner;

use App\Models\PaymentMethod;
use App\Models\ReferralEvent;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RK-01 (Train 2; smart-build-queue.md Section 4; copy
 * Amendment 22): the Owner Action Center aggregates every
 * state waiting on the owner into one inbox in the owner
 * shell. Each source state produces exactly one row with the
 * deep link to the page that resolves it; resolving the state
 * removes the row; a dismissal sticks until the state itself
 * changes; when nothing is open the page renders the quiet
 * healthy line and the shell carries no badge.
 */
class ActionCenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A business with every Action Center source closed:
     * checklist complete, billing active, a healthy card on
     * file, no holds, no failed deliveries.
     */
    private function healthyBusiness(array $overrides = [])
    {
        $business = World::business(array_merge([
            'square_customer_id' => 'sq_customer_1',
            // LV-02's two added checklist steps are closed too:
            // the review link is set, and below the owner joins
            // their own program (test referral step).
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ], $overrides));

        // The checklist's email step reads the OWNER USER's
        // verification, which World cannot mass-assign (the
        // column is not fillable), so pin it the way the Train 1
        // suites pin verified state.
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        World::membership($business, World::customer($owner->phone_e164));

        ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
        ]);

        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_1',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);

        return $business->fresh();
    }

    private static int $holdSequence = 0;

    private function holdReferral($business): void
    {
        // Each call needs a fresh referrer/friend pair: the
        // referrals table enforces one ACTIVE referral per
        // (membership, friend) pair.
        self::$holdSequence++;
        $suffix = str_pad((string) self::$holdSequence, 2, '0', STR_PAD_LEFT);
        $referrer = World::customer('+121255502'.$suffix, 'Pat', 'One');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+121255503'.$suffix, 'Fran', 'Two');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['hold' => true, 'held_at' => now()])->save();
    }

    public function test_review_hold_produces_one_row_with_the_review_link(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);
        $this->holdReferral($business);

        // Another tenant's hold must never leak into this inbox.
        $other = World::business();
        $this->holdReferral($other);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('Action Center');
        $page->assertSee('1 item is waiting for your review.');
        $page->assertSee('Open review');
        $page->assertSee('href="'.route('reviews').'"', false);
        $page->assertDontSee('Account in good standing');
    }

    public function test_past_due_produces_a_row_with_the_billing_link(): void
    {
        $business = $this->healthyBusiness(['billing_status' => 'past_due']);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('A payment failed. Please update your card to keep billing current.');
        $page->assertSee('Update card');
        $page->assertSee('href="'.route('settings.billing').'#card"', false);
        $page->assertDontSee('past_due');
    }

    public function test_expired_card_produces_a_row(): void
    {
        $business = $this->healthyBusiness();
        PaymentMethod::query()->where('business_id', $business->id)
            ->update(['exp_month' => 1, 'exp_year' => (int) now()->subYear()->format('Y')]);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('Your card on file has expired. Replace it to keep monthly charges going.');
        $page->assertSee('Replace card');
    }

    public function test_failed_campaign_deliveries_produce_a_row(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);
        $customer = World::customer('+12125550103', 'Cam', 'Three');
        [$membership] = World::membership($business, $customer);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'A past campaign',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'sent_at' => now(),
        ]);
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_FAILED,
        ]);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('1 text could not be delivered in a recent campaign.');
        $page->assertSee('View campaign');
        $page->assertSee('href="'.route('campaigns.show', $campaign).'"', false);
    }

    public function test_unfinished_checklist_produces_a_row(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        // A plain World business has two of its four steps done:
        // the reward text ships set, and the owner's email is
        // verified here (World cannot mass-assign the user's
        // verification column, so it is pinned by force-fill,
        // the Train 1 pattern). Open: poster, card. (Train 2
        // LV-02 extends the checklist to six and this count
        // becomes 2 of 6.)
        $owner->forceFill(['email_verified_at' => now()])->save();

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('Your setup is not finished: 2 of 6 steps done.');
        $page->assertSee('Finish setup');
        $page->assertSee('href="'.route('dashboard').'"', false);
    }

    public function test_reward_held_too_long_produces_its_own_row(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550104', 'Rue', 'Four');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550105', 'Fay', 'Five');
        [$referral] = World::referral($membership, $friend);
        [$reward] = World::reward($membership, $referral);
        $reward->forceFill([
            'hold' => true,
            'held_at' => now()->subDays(10),
            'state' => 'pending_vesting',
        ])->save();

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('A reward has been waiting 10 days for review.');
    }

    public function test_resolving_the_state_removes_the_row(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);
        $this->holdReferral($business);

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertSee('1 item is waiting for your review.');

        \App\Models\Referral::query()->where('business_id', $business->id)
            ->update(['hold' => false]);

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertDontSee('waiting for your review')
            ->assertSee('✓ Account in good standing');
    }

    public function test_dismiss_persists_until_the_state_changes(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);
        $this->holdReferral($business);

        $this->actingAs($owner)->post(route('actions.dismiss', 'review_holds'))
            ->assertRedirect();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertDontSee('waiting for your review')
            ->assertSee('✓ Account in good standing');

        // A second hold is a changed state: the row resurfaces.
        $this->holdReferral($business);

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertSee('2 items are waiting for your review.');
    }

    public function test_empty_state_renders_the_healthy_line_and_no_badge(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('✓ Account in good standing');
        $page->assertDontSee('data-action-center-badge', false);

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertDontSee('data-action-center-badge', false);
    }

    public function test_badge_counts_the_open_items(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);
        $this->holdReferral($business);

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('data-action-center-badge', false);
        $dashboard->assertSee('>1</span>', false);
    }

    public function test_dismissing_an_unknown_key_is_a_404(): void
    {
        $business = $this->healthyBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('actions.dismiss', 'not_a_source'))
            ->assertNotFound();
    }
}
