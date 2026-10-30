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

        // ON-SET-2 (Amendment 31): the card still RENDERS all
        // six rows, but its progress counts required steps
        // only — 2 of 4 here (email, rewards) — the review
        // row is labelled optional, and its open state is the
        // neutral circle, not the alert glyph.
        // RE-DERIVED (activation redesign, census C-24,
        // 2026-10-30): the card row is OPTIONAL in the count,
        // but the blade's standing glyph design keeps the
        // alert glyph on it (the code does pause without a
        // card once the free visits end); the review row is
        // the only neutral-circle row. So exactly three alert
        // glyphs still render — card, poster, test — counted
        // by the circle-alert line signature; what changed is
        // that only two of the three are REQUIRED rows.
        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response
            ->assertOk()
            ->assertSee('2 of 4 required steps done')
            ->assertSee('aria-valuemax="4"', false)
            ->assertSee('aria-valuenow="2"', false)
            ->assertSee('Add your Google review link (optional)')
            ->assertSee('Send yourself a test referral')
            ->assertSee('href="'.route('settings.reviews').'"', false)
            ->assertSee('href="'.route('owner.qr').'"', false);
        // Scoped to the card itself (the dashboard renders
        // other alert glyphs elsewhere): inside the checklist
        // card, exactly the three required open rows carry it.
        $cardHtml = substr($response->getContent(), strpos($response->getContent(), 'Setup checklist'));
        $cardHtml = substr($cardHtml, 0, strpos($cardHtml, 'Scans today'));
        $this->assertSame(3, substr_count($cardHtml, '<line x1="12" x2="12" y1="8" y2="12" />'));
    }

    public function test_setting_the_review_link_and_self_membership_close_the_new_steps(): void
    {
        $business = World::business([
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        World::membership($business, World::customer($owner->phone_e164));

        // ON-SET-2: the two closed steps were the test referral
        // (required) and the review link (optional), so the
        // required count moves 2 -> 3 of 4 (the card is
        // optional under the activation redesign, census C-24).
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('3 of 4 required steps done');
    }

    public function test_settings_reviews_page_marks_the_link_optional(): void
    {
        // ON-SET-1 (Amendment 31) verbatim: the label, the
        // help line, and the empty-state pill; the set state
        // is unchanged.
        $business = World::business(['google_review_url' => null]);
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.reviews'))
            ->assertOk()
            ->assertSee('Google review link (optional)')
            ->assertSee("Paste your Google review link if you have one. Leave it empty if you don't: customers just won't see the review link on their receipts, and you can add it any time. We never ask anyone for a positive review.", false)
            ->assertSee('Not set (optional)')
            ->assertDontSee('Not set yet');

        // The Settings index state pill carries the same
        // honesty (ON-SET-3 sweep fix).
        $this->actingAs($owner)->get(route('settings'))
            ->assertOk()
            ->assertSee('Not set (optional)');

        $set = World::business(['google_review_url' => 'https://g.page/r/example/review']);
        $this->actingAs(World::owner($set))->get(route('settings.reviews'))
            ->assertOk()
            ->assertSee('Link set');
    }

    public function test_card_absent_when_only_the_optional_review_link_is_open(): void
    {
        // Every required step done, the review link unset:
        // no checklist card, no Action Center setup row, and
        // the Overview reads setup done (the OV-5 slim row) —
        // the greeting/banner and the card agree (ON-SET-2).
        $business = World::business(['google_review_url' => null]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_onset', 'card_brand' => 'Visa',
            'last4' => '4242', 'exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => true, 'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
        ]);
        \App\Models\ReferralEvent::create([
            'business_id' => $business->id, 'event_type' => 'poster_downloaded', 'actor' => 'owner',
        ]);
        World::membership($business, World::customer($owner->phone_e164));

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Setup checklist')
            ->assertDontSee('Add your Google review link')
            ->assertDontSee("You're partway through setup", false)
            ->assertSee('Setup complete.');

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('Your setup is not finished');
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
