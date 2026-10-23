<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\User;
use App\Support\CanonicalEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The plaintext password every factory user shares. The model's
     * hashed cast turns it into an Argon2id hash at persistence time.
     */
    public const PASSWORD = 'fixture-password-123';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'role' => 'owner',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'canonical_email' => fn (array $attributes) => CanonicalEmail::of($attributes['email']),
            'phone_e164' => '+1'.fake()->unique()->numerify('##########'),
            'email_verified_at' => now(),
            'password' => self::PASSWORD,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
