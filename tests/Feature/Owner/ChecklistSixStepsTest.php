<?php

namespace Tests\Feature\Owner;

use App\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * LV-02 (Train 2; copy Amendment 22 DASH-16, DASH-17, AC-09,
 * REV-02): the setup checklist grows to six steps (Google
 * review link, self test referral), the Action Center gains
 * the review-link row once a business has a redeemed referral
 * but no review link, and every review hold shows its age.
 * Plus the coordinator-added micro-unit: the dashboard QR
 * card's poster row is honest navigation wording ("Print
 * your posters"), never a "Download" that downloads nothing.
 */
class ChecklistSixStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_renders_six_steps_with_the_two_new_rows(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('2 of 6 done')
            ->assertSee('Add your Google review link')
            ->assertSee('Send yourself a test referral')
            ->assertSee('href="'.route('settings.reviews').'"', false)
            ->assertSee('href="'.route('owner.qr').'"', false);
    }

    public function test_setting_the_review_link_and_self_membership_close_the_new_steps(): void
    {
        $business = World::business([
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        World::membership($business, World::customer($owner->phone_e164));

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('4 of 6 done');
    }

    public function test_review_link_action_center_row_fires_only_after_a_redemption(): void
    {
        $business = World::business(['square_customer_id' => 'sq_lv']);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        // No redeemed referral yet: the row stays silent.
        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('Add your Google review link so customers can leave a review after they visit.');

        // A redeemed referral lands: the row appears.
        $referrer = World::customer('+12125550601', 'Pat', 'One');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550602', 'Fran', 'Two');
        [$referral] = World::referral($membership, $friend);
        // Mirrors RedemptionService's own redemption write:
        // state redeemed, redeemed_at stamped, attribution set.
        $referral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now(),
            'redeemed_by_user_id' => $owner->id,
            'attribution_state' => 'attributed',
        ])->save();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Add your Google review link so customers can leave a review after they visit.')
            ->assertSee('Add review link');

        // Setting the link closes the row. (Written through a
        // fresh query, and the guard-held owner instance drops
        // its cached business relation, which the earlier
        // requests in this test already loaded.)
        \App\Models\Business::query()->whereKey($business->id)
            ->update(['google_review_url' => 'https://www.google.com/maps/place/example']);
        $owner->unsetRelation('business');

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('Add your Google review link so customers can leave a review after they visit.');
    }

    public function test_review_holds_show_their_waiting_age(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $referrer = World::customer('+12125550611', 'Pat', 'One');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550612', 'Fran', 'Two');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['hold' => true, 'held_at' => now()->subDays(3)])->save();

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee('Waiting 3 days');
    }

    public function test_dashboard_poster_row_is_honest_navigation_wording(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        // The QR rows render in the collapsed card once the
        // poster step is done.
        \App\Models\ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Print your posters')
            ->assertDontSee('Download the on-screen poster');
    }
}
