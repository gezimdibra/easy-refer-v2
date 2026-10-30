<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Address validation against application data (spec Section
 * 4.1; review finding M-8): ISO country codes, real US states,
 * ZIP format, and ZIP/state consistency. RS2-2 moved the
 * address out of signup: these rules now run at the onboarding
 * Location step (onboarding.location.store), under the
 * extracted App\Support\AddressRules, for a checkpoint owner.
 */
class AddressValidationTest extends TestCase
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
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    /** A persisted checkpoint account: the account exists, the address does not. */
    private function checkpointOwner(): User
    {
        $business = Business::factory()->create([
            'country' => null,
            'street' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
        ]);

        return User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
    }

    public function test_an_unknown_country_code_is_refused(): void
    {
        $owner = $this->checkpointOwner();

        $this->actingAs($owner)->post(route('onboarding.location.store'), $this->payload(['country' => 'XX']))
            ->assertSessionHasErrors('country');

        $this->assertNull($owner->business->fresh()->street);
    }

    public function test_a_us_signup_with_a_bogus_state_is_refused(): void
    {
        $owner = $this->checkpointOwner();

        $this->actingAs($owner)->post(route('onboarding.location.store'), $this->payload(['state' => 'ZZ']))
            ->assertSessionHasErrors('state');
    }

    public function test_a_malformed_us_zip_is_refused(): void
    {
        $owner = $this->checkpointOwner();

        $this->actingAs($owner)->post(route('onboarding.location.store'), $this->payload(['postal_code' => '1000']))
            ->assertSessionHasErrors('postal_code');
    }

    public function test_a_zip_outside_the_states_region_is_refused(): void
    {
        $owner = $this->checkpointOwner();

        // 90210 is Beverly Hills, California; New York cannot claim it.
        $this->actingAs($owner)->post(route('onboarding.location.store'), $this->payload([
            'city' => 'Beverly Hills',
            'state' => 'NY',
            'postal_code' => '90210',
        ]))->assertSessionHasErrors('postal_code');
    }

    public function test_a_consistent_us_address_is_accepted(): void
    {
        $owner = $this->checkpointOwner();

        $this->actingAs($owner)->post(route('onboarding.location.store'), $this->payload([
            'city' => 'Beverly Hills',
            'state' => 'CA',
            'postal_code' => '90210',
        ]))->assertRedirect(route('onboarding.step', 'verify'));

        $business = $owner->business->fresh();
        $this->assertSame('1 Main Street', $business->street);
        $this->assertSame('Beverly Hills', $business->city);
        $this->assertSame('CA', $business->state);
    }

    public function test_a_non_us_address_keeps_free_form_region_fields(): void
    {
        $owner = $this->checkpointOwner();

        $this->actingAs($owner)->post(route('onboarding.location.store'), $this->payload([
            'country' => 'CA',
            'city' => 'Toronto',
            'state' => 'Ontario',
            'postal_code' => 'M4B 1B3',
        ]))->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertSame('Ontario', $owner->business->fresh()->state);
    }
}
