<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Support\StarterPacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\World;
use Tests\TestCase;

/**
 * P1 TYPES (critic report 2026-10-29): the register wizard's
 * business types omitted Restaurant, a core trade for this
 * product (the owner's own first test business is a
 * pizzeria). Restaurant joins Business::TYPES in the list's
 * own order (with the food trades, after Cafe), which feeds
 * both selects (register Step 1, Settings -> Profile) and
 * both validation rules from the one source. The type also
 * gets its own starter packs, so a restaurant lands on a
 * restaurant starter default instead of the generic pack.
 */
class RestaurantBusinessTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => "Gezim's Pizzeria",
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    public function test_restaurant_is_in_the_type_list_with_the_food_trades(): void
    {
        $this->assertSame(
            ['barber', 'salon', 'cafe', 'restaurant', 'boutique', 'other'],
            Business::TYPES
        );
    }

    public function test_the_wizard_offers_restaurant_and_registration_completes(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('value="restaurant"', false)
            ->assertSee('Restaurant');

        $this->post(route('register'), $this->payload(['business_type' => 'restaurant']))
            ->assertRedirect(route('onboarding.welcome'));

        $this->assertSame('restaurant', Business::firstOrFail()->business_type);
    }

    public function test_restaurant_has_its_own_starter_packs_and_default(): void
    {
        $packs = StarterPacks::forType('restaurant');

        $this->assertCount(3, $packs);
        $this->assertNotSame(StarterPacks::forType('other'), $packs, 'a restaurant must not land on the generic pack');
        $this->assertSame('A free appetizer', $packs[0]['reward']);
        $this->assertSame($packs[0], StarterPacks::starterFor('restaurant'));
    }

    public function test_a_restaurant_owner_sees_restaurant_packs_on_the_dashboard_card(): void
    {
        // The starter-pack card renders while no reward is set
        // yet (DashboardController::showStarterPack).
        $business = World::business([
            'business_type' => 'restaurant',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'reward_split' => false,
        ]);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('A free appetizer');
        $page->assertSee('A free dessert');
    }

    public function test_settings_profile_offers_restaurant_and_saves_it(): void
    {
        $business = World::business(['business_type' => 'cafe']);
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('settings.profile'));
        $page->assertOk();
        $page->assertSee('value="restaurant"', false);
        $page->assertSee('Restaurant');

        $this->actingAs($owner)->post(route('settings.profile.update'), [
            'name' => $business->name,
            'business_type' => 'restaurant',
            'contact_email' => $business->contact_email,
            'contact_phone' => '(212) 555-0401',
            'street' => $business->street,
            'city' => $business->city,
            'state' => 'NY',
            'postal_code' => $business->postal_code,
            'country' => 'US',
        ])->assertSessionHasNoErrors();

        $this->assertSame('restaurant', $business->fresh()->business_type);
    }
}
