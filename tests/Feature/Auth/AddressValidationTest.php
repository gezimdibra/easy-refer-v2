<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signup address validation against application data (spec Section
 * 4.1; review finding M-8): ISO country codes, real US states, ZIP
 * format, and ZIP/state consistency.
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
            'business_name' => 'Corner Deli',
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

    public function test_an_unknown_country_code_is_refused(): void
    {
        $this->post(route('register'), $this->payload(['country' => 'XX']))
            ->assertSessionHasErrors('country');

        $this->assertGuest();
    }

    public function test_a_us_signup_with_a_bogus_state_is_refused(): void
    {
        $this->post(route('register'), $this->payload(['state' => 'ZZ']))
            ->assertSessionHasErrors('state');
    }

    public function test_a_malformed_us_zip_is_refused(): void
    {
        $this->post(route('register'), $this->payload(['postal_code' => '1000']))
            ->assertSessionHasErrors('postal_code');
    }

    public function test_a_zip_outside_the_states_region_is_refused(): void
    {
        // 90210 is Beverly Hills, California; New York cannot claim it.
        $this->post(route('register'), $this->payload([
            'city' => 'Beverly Hills',
            'state' => 'NY',
            'postal_code' => '90210',
        ]))->assertSessionHasErrors('postal_code');
    }

    public function test_a_consistent_us_address_is_accepted(): void
    {
        $this->post(route('register'), $this->payload([
            'city' => 'Beverly Hills',
            'state' => 'CA',
            'postal_code' => '90210',
        ]))->assertRedirect(route('dashboard'));
    }

    public function test_a_non_us_address_keeps_free_form_region_fields(): void
    {
        $this->post(route('register'), $this->payload([
            'country' => 'CA',
            'city' => 'Toronto',
            'state' => 'Ontario',
            'postal_code' => 'M4B 1B3',
        ]))->assertRedirect(route('dashboard'));
    }
}
