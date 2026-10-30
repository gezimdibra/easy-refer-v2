<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-INACTIVE-01: when a scanned code DOES resolve to a
 * business whose program is not active yet, the customer
 * page names that business (a line in the body and the page
 * title) so the customer can confirm they are in the right
 * shop. The designed message text and the 404 stand. A code
 * that resolves to nothing keeps the generic page: no name,
 * the standard "Page not found" title — unknown, revoked,
 * and not-yet-active stay indistinguishable only where
 * nothing was resolved.
 */
class FlowInactiveBusinessNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_code_page_names_the_business_it_belongs_to(): void
    {
        // RE-DERIVED (activation redesign, 2026-10-30): the
        // original fixture (no card, billing 'none') is LIVE
        // under the allowance gate. The FE-INACTIVE-01 state
        // is reached here through the reward leg instead: no
        // friend reward text set, so the flow answers the
        // generic not_active page — the same page this pin
        // names the business on.
        $business = World::business(['name' => 'Tonys Pizza', 'billing_status' => 'none', 'friend_reward_text' => null]);
        [, $qrToken] = World::qr($business);

        $response = $this->get(route('refer.landing', ['token' => $qrToken]));

        $response->assertNotFound();
        // The designed message, unchanged.
        $response->assertSee('This code is not active');
        $response->assertSee('Please ask the business for help, or check that you scanned the whole code.');
        // The title names the business, not the error.
        $response->assertSee('<title>Tonys Pizza | Easy Refer</title>', false);
        $response->assertDontSee('Page not found');
        // The name is a line in the body too, not only the
        // title: it appears at least twice in the document.
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'Tonys Pizza'));
    }

    public function test_unresolved_code_keeps_the_generic_page(): void
    {
        $response = $this->get(route('refer.landing', ['token' => 'not-a-real-code']));

        $response->assertNotFound();
        $response->assertSee('This code is not active');
        $response->assertSee('<title>Page not found | Easy Refer</title>', false);
    }
}
