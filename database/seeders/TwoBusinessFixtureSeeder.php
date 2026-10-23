<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Support\CanonicalEmail;
use Illuminate\Database\Seeder;

/**
 * The verification protocol's standard fixture (protocol Section 5.3):
 * exactly two test businesses, A and B, each with one verified owner.
 * Test data only; this seeder is never run against a production or
 * staging database that holds real businesses.
 */
class TwoBusinessFixtureSeeder extends Seeder
{
    public const A_EMAIL = 'owner.a@example.test';

    public const A_PASSWORD = 'alpha-fixture-pass-1';

    public const B_EMAIL = 'owner.b@example.test';

    public const B_PASSWORD = 'beta-fixture-pass-1';

    public function run(): void
    {
        $alpha = Business::query()->firstOrCreate(
            ['contact_email' => self::A_EMAIL],
            [
                'name' => 'Alpha Bakery',
                'contact_phone_e164' => '+12125550101',
                'country' => 'US',
                'street' => '100 Alpha Street',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'billing_status' => 'none',
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => self::A_EMAIL],
            [
                'business_id' => $alpha->id,
                'role' => 'owner',
                'name' => 'Alpha Owner',
                'canonical_email' => CanonicalEmail::of(self::A_EMAIL),
                'phone_e164' => '+12125550101',
                'password' => self::A_PASSWORD,
                'email_verified_at' => now(),
            ]
        );

        $beta = Business::query()->firstOrCreate(
            ['contact_email' => self::B_EMAIL],
            [
                'name' => 'Beta Barbers',
                'contact_phone_e164' => '+12125550202',
                'country' => 'US',
                'street' => '200 Beta Avenue',
                'city' => 'Albany',
                'state' => 'NY',
                'postal_code' => '12201',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'billing_status' => 'none',
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => self::B_EMAIL],
            [
                'business_id' => $beta->id,
                'role' => 'owner',
                'name' => 'Beta Owner',
                'canonical_email' => CanonicalEmail::of(self::B_EMAIL),
                'phone_e164' => '+12125550202',
                'password' => self::B_PASSWORD,
                'email_verified_at' => now(),
            ]
        );
    }
}
