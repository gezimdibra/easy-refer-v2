<?php

namespace Tests\Feature;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\World;
use Tests\TestCase;

/**
 * STATE FIELD fix (F-2, 2026-10-29) — RE-DERIVED at the RS2-2
 * merge (2026-10-30). The defect: a free-text state field that
 * only accepted the 2-letter code refused a typed "New York"
 * with "Select the state from the list" while no list existed
 * on the screen, and stacked a phantom ZIP error on top.
 *
 * The fix originally landed on the register form (a real state
 * select + server-side name normalization) and on the Settings
 * profile form (same hybrid; those pins below are unchanged).
 * RS2-2 then moved the address OUT of register entirely: the
 * account persists at the identity checkpoint and the address
 * is collected at the onboarding Location step. The register
 * pins are therefore re-derived HERE, at the address's new
 * home, against the property they always protected: a typed
 * state name never dead-ends. The Location step renders a text
 * input by RS2-2 design, so the protection is the server-side
 * normalization (OnboardingLocationRequest maps a full name to
 * its code before validation) — pinned behaviorally below. The
 * old "register renders a state select" rendering pin retired
 * with its surface; the country-default pin moved with the
 * country select to the Location step.
 *
 * Error pins follow the EMPTY ERROR SUMMARY class rule: the
 * message text must render inside the summary region AND at
 * the field.
 */
class StateFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    /**
     * Register through the identity checkpoint (RS2-2): the
     * account and business persist with NO address yet, and the
     * owner is signed in. Returns the persisted business.
     *
     * @param  array<string, string>  $overrides
     */
    private function checkpoint(array $overrides = []): Business
    {
        $payload = array_merge([
            'business_name' => 'Corner Deli',
            'business_type' => 'cafe',
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
        ], $overrides);

        $this->post(route('register.store'), $payload)
            ->assertRedirect(route('onboarding.welcome'));
        $this->assertAuthenticated();

        $business = Business::query()->where('name', $payload['business_name'])->firstOrFail();
        $this->assertNull($business->street, 'the checkpoint persists no address (RS2-2)');

        return $business;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function locationPayload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    private function summaryRegion(string $html): string
    {
        $this->assertMatchesRegularExpression('/data-error-summary>/', $html, 'no error summary rendered at all');

        preg_match('/data-error-summary>(.*?)<\/div>/s', $html, $m);

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    private function assertStateErrorEverywhere(string $html, string $message): void
    {
        // Inside the single-source summary region...
        $this->assertStringContainsString($message, $this->summaryRegion($html));
        $this->assertStringContainsString('href="#state"', $this->summaryRegion($html));

        // ...and at the field itself.
        $this->assertMatchesRegularExpression(
            '/id="state-error"[^>]*>\s*'.preg_quote($message, '/').'\s*</s',
            $html,
            'state message did not render at the field: '.$message
        );
    }

    public function test_location_renders_the_country_select_with_us_default_and_every_country(): void
    {
        $this->checkpoint();

        $html = $this->get(route('onboarding.step', 'location'))->assertOk()->getContent();

        preg_match('/<select id="country".*?<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'country select not rendered');
        $select = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');

        $this->assertMatchesRegularExpression('/<option value="US"[^>]*selected[^>]*>United States<\/option>/', $select);
        $this->assertSame(count((array) config('countries.codes')), substr_count($select, '<option value='));

        foreach ((array) config('countries.codes') as $code) {
            $this->assertStringContainsString('<option value="'.$code.'"', $select, 'country option missing: '.$code);
        }

        // The state field is a text input by RS2-2 design; the
        // anti-dead-end protection is behavioral (the pins
        // below), not a rendered list.
        $this->assertMatchesRegularExpression('/<input id="state" name="state" type="text"/', $html);
    }

    public function test_location_with_a_valid_code_submits_and_stores_the_code(): void
    {
        $business = $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->locationPayload(['state' => 'NY']))
            ->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertSame('NY', $business->fresh()->state);
    }

    public function test_location_with_the_full_state_name_now_succeeds_and_stores_the_code(): void
    {
        // The exact path the timing lane found refused: a cold
        // owner typing the name of their state.
        $business = $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->locationPayload(['state' => 'New York']))
            ->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertSame('NY', $business->fresh()->state);
    }

    public function test_location_with_a_lowercase_full_state_name_normalizes(): void
    {
        $business = $this->checkpoint(['email' => 'lower@example.test', 'phone' => '(212) 555-0148']);

        $this->post(route('onboarding.location.store'), $this->locationPayload(['state' => 'new york']))
            ->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertSame('NY', $business->fresh()->state);
    }

    public function test_location_missing_state_error_renders_in_the_summary_and_at_the_field(): void
    {
        $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->locationPayload(['state' => '']))
            ->assertSessionHasErrors('state');

        $html = $this->get(route('onboarding.step', 'location'))->assertOk()->getContent();

        $this->assertStateErrorEverywhere($html, 'Enter your state or province.');
    }

    public function test_location_bogus_state_error_renders_in_the_summary_and_at_the_field(): void
    {
        $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->locationPayload(['state' => 'ZZ']))
            ->assertSessionHasErrors('state');

        $html = $this->get(route('onboarding.step', 'location'))->assertOk()->getContent();

        $this->assertStateErrorEverywhere($html, 'Select the state from the list.');
    }

    public function test_location_non_us_keeps_the_free_text_province(): void
    {
        $business = $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->locationPayload([
            'country' => 'CA',
            'city' => 'Toronto',
            'state' => 'Ontario',
            'postal_code' => 'M4B 1B3',
        ]))->assertRedirect(route('onboarding.step', 'verify'));

        $fresh = $business->fresh();
        $this->assertSame('Ontario', $fresh->state);
        $this->assertSame('CA', $fresh->country);
    }

    public function test_location_with_a_full_name_and_a_mismatched_zip_refuses_on_the_zip_only(): void
    {
        // The F-2 audit's phantom ZIP error: with "New York"
        // typed, the ZIP rule ALSO fired because it compared the
        // un-normalized name against the code lists, stacking a
        // second phantom error on the first. After normalization
        // the state is accepted, and a genuinely mismatched ZIP
        // refuses on the ZIP alone.
        $this->checkpoint();

        $this->post(route('onboarding.location.store'), $this->locationPayload([
            'state' => 'New York',
            'postal_code' => '90210',
        ]))->assertSessionHasErrors('postal_code')
            ->assertSessionDoesntHaveErrors('state');
    }

    public function test_profile_renders_the_state_select_and_preselects_the_stored_code(): void
    {
        $business = World::business(['state' => 'NY', 'country' => 'US']);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings.profile'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select id="state" name="state"/', $html);
        $this->assertStringContainsString('<option value="">Choose your state</option>', $html);
        $this->assertMatchesRegularExpression('/<option value="NY" selected>New York<\/option>/', $html);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Corner Deli',
            'contact_email' => 'hello@example.test',
            'contact_phone' => '(212) 555-0401',
            'street' => '9 New Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ], $overrides);
    }

    public function test_profile_with_the_full_state_name_stores_the_code(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.profile.update'), $this->profilePayload(['state' => 'New York']))
            ->assertSessionHas('status', 'Business profile saved.');

        $this->assertSame('NY', $business->fresh()->state);
    }

    public function test_profile_missing_state_error_renders_in_the_summary_and_at_the_field(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->from(route('settings.profile'))->followingRedirects()
            ->post(route('settings.profile.update'), $this->profilePayload(['state' => '']))
            ->assertOk()->getContent();

        $this->assertStateErrorEverywhere($html, 'Choose your state.');
    }

    public function test_profile_bogus_us_state_is_refused_against_the_list(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $original = $business->state;

        $this->actingAs($owner)->post(route('settings.profile.update'), $this->profilePayload(['state' => 'ZZ']))
            ->assertSessionHasErrors(['state' => 'Select the state from the list.']);

        $this->assertSame($original, $business->fresh()->state);
    }
}
