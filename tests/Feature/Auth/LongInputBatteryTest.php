<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The EC-44 long-input battery at signup (matrix AUTH-45; tester
 * finding G-5): hostile, oversized values in every free-text field
 * the wizard collects are refused by the field rules, never by a
 * 500, and never by silent truncation; no partial account is left
 * behind by any of them.
 */
class LongInputBatteryTest extends TestCase
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function oversizedFields(): iterable
    {
        $huge = str_repeat('x', 10000);

        return [
            'business name' => [['business_name' => $huge], 'business_name'],
            'street' => [['street' => $huge], 'street'],
            'city' => [['city' => $huge], 'city'],
            'owner name' => [['name' => $huge], 'name'],
            'email' => [['email' => $huge.'@example.test'], 'email'],
            'postal code' => [['postal_code' => $huge], 'postal_code'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('oversizedFields')]
    public function test_oversized_signup_fields_are_rejected_cleanly(array $override, string $field): void
    {
        $response = $this->post(route('register'), $this->payload($override));

        $response->assertSessionHasErrors($field);
        $this->assertGuest();
        $this->assertSame(0, Business::count());
        $this->assertSame(0, User::count());
    }

    public function test_an_oversized_password_is_rejected_by_the_policy_maximum(): void
    {
        $huge = str_repeat('x', 10000);

        $response = $this->post(route('register'), $this->payload([
            'password' => $huge,
            'password_confirmation' => $huge,
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
