<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => null,
            'contact_email' => fake()->unique()->companyEmail(),
            'contact_phone_e164' => '+1'.fake()->numerify('##########'),
            'country' => 'US',
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'timezone' => 'America/New_York',
            'status' => 'active',
            'billing_status' => 'none',
            'free_visits_remaining' => 5,
        ];
    }
}
