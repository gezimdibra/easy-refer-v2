<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Entry points to the billing page (billing UI spec Sections 1
 * and 7 step 3, build unit BU-3): the setup checklist's card row
 * is a link to settings.billing while the step is open, which is
 * what closes the DG-2 dead-end, and the dashboard value panel
 * carries a "Billing details" link to the same page. The page
 * itself is BU-2's; these pins hold the doors into it.
 */
class BillingEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_card_row_links_to_billing_while_the_step_is_open(): void
    {
        // World businesses carry no Square customer record, so the
        // card step is open and the checklist renders.
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            // The open card row is an anchor, in the same shape
            // as every other open checklist row (the DG-2
            // regression pin: this row used to be a plain status
            // row with no destination). P4 (OB-U6): while the
            // onboarding flow is open the anchor leads into the
            // flow's Card step; the billing page is its target
            // again once the flow is completed (asserted below).
            ->assertSee('<a class="font-semibold text-link hover:underline" href="'.route('onboarding.step', 'card').'">Add a card (keeps your code on after your free visits)</a>', false);

        $business->forceFill(['onboarding_completed_at' => now()])->save();
        $owner->unsetRelation('business');

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<a class="font-semibold text-link hover:underline" href="'.route('settings.billing').'">Add a card (keeps your code on after your free visits)</a>', false);
    }

    public function test_checklist_card_row_is_a_plain_done_row_once_a_card_is_on_file(): void
    {
        // card_on_file derives from the verified saved-card
        // record (coherence Phase 2, G2 — it read the Square
        // customer record alone until then, which a failed save
        // also leaves set); the other steps stay open, so the
        // checklist still renders with the card row done.
        $business = World::business(['square_customer_id' => 'cus_entry_points']);
        $owner = World::owner($business);
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_entry_points',
            'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'is_default' => true,
            'brand' => 'VISA',
            'last4' => '1111',
            'exp_month' => 12,
            'exp_year' => now()->addYear()->year,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Add a card (keeps your code on after your free visits)')
            ->assertDontSee('>Add a card (keeps your code on after your free visits)</a>', false);
    }

    public function test_value_panel_links_to_billing_details(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('What Easy Refer brought you')
            ->assertSee('<a class="font-semibold text-link hover:underline" href="'.route('settings.billing').'">Billing details</a>', false);
    }
}
