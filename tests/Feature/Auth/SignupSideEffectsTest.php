<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signup side effects and identity uniqueness (spec Sections 4.1 and
 * 7.2, review findings H-5 and M-6): the launch QR code row, the OD-1
 * free visit credit, database-level uniqueness of the canonical
 * identity, and a graceful generic error when a duplicate races past
 * the form check.
 */
class SignupSideEffectsTest extends TestCase
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

    public function test_signup_creates_one_active_qr_code_and_grants_five_free_visits(): void
    {
        $this->post(route('register'), $this->payload())->assertRedirect(route('dashboard'));

        $business = Business::firstOrFail();

        $this->assertSame(5, $business->free_visits_remaining);

        $qrCodes = QrCode::where('business_id', $business->id)->get();
        $this->assertCount(1, $qrCodes);
        $this->assertCount(1, $business->qrCodes);
        $this->assertTrue($qrCodes->first()->isActive());
        $this->assertSame(64, strlen($qrCodes->first()->token_hash));
        $this->assertSame($business->id, $qrCodes->first()->business->id);
    }

    public function test_canonical_email_is_unique_at_the_database_level(): void
    {
        User::factory()->create(['canonical_email' => 'shared@example.test']);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'email' => 'different@example.test',
            'canonical_email' => 'shared@example.test',
        ]);
    }

    public function test_phone_is_unique_at_the_database_level(): void
    {
        User::factory()->create(['phone_e164' => '+12125550199']);

        $this->expectException(QueryException::class);

        User::factory()->create(['phone_e164' => '+12125550199']);
    }

    public function test_a_duplicate_racing_past_the_form_check_gets_the_generic_error_not_a_500(): void
    {
        // Simulate the race: after the form check passes, a conflicting
        // account lands first (here, synchronously inside the creating
        // event), so the insert itself hits the unique index.
        User::creating(function (User $user) {
            DB::table('users')->insert([
                'business_id' => $user->business_id,
                'role' => 'owner',
                'name' => 'Racer',
                'email' => 'racer@example.test',
                'canonical_email' => $user->canonical_email,
                'phone_e164' => '+19995550100',
                'password' => 'irrelevant',
            ]);
        });

        $response = $this->post(route('register'), $this->payload());

        $response->assertSessionHasErrors('account');
        $this->assertGuest();
    }
}
