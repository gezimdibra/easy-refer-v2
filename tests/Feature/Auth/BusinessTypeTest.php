<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signup Step 1 business type (spec Sections 4.1 and 7.2; finding
 * FB-02). The field is optional, takes only the specced values, and
 * lands on the business row the wizard creates.
 */
class BusinessTypeTest extends TestCase
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

    public function test_the_wizard_offers_the_business_type_field_at_step_one(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('business_type')
            ->assertSee('Barber')
            ->assertSee('Boutique');
    }

    public function test_a_chosen_business_type_is_stored_on_the_business(): void
    {
        $this->post(route('register'), $this->payload(['business_type' => 'cafe']))
            ->assertRedirect(route('onboarding.welcome'));

        $this->assertSame('cafe', Business::firstOrFail()->business_type);
    }

    public function test_business_type_is_optional(): void
    {
        $this->post(route('register'), $this->payload())
            ->assertRedirect(route('onboarding.welcome'));

        $this->assertNull(Business::firstOrFail()->business_type);
    }

    public function test_an_unlisted_business_type_is_rejected(): void
    {
        $this->post(route('register'), $this->payload(['business_type' => 'spaceship']))
            ->assertSessionHasErrors('business_type');

        $this->assertSame(0, Business::count());
    }

    public function test_every_specced_type_is_accepted(): void
    {
        // The register POST is throttled at 5/minute; with
        // Restaurant the specced list has six entries, so the
        // loop outruns the limit on its own subject's account.
        // The throttle has its own pins; this test's subject
        // is type acceptance, so it lifts the throttle only.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        foreach (Business::TYPES as $i => $type) {
            $this->post(route('logout'));

            $this->post(route('register'), $this->payload([
                'business_type' => $type,
                'email' => 'owner'.$i.'@example.test',
                'phone' => '(212) 555-02'.$i.'0',
            ]))->assertRedirect(route('onboarding.welcome'));

            $this->assertSame(
                $type,
                Business::where('contact_email', 'owner'.$i.'@example.test')->firstOrFail()->business_type
            );
        }
    }
}
