<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'designation' => fake()->jobTitle(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->unique()->numerify('9#########'),
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Neither channel verified (blocks login until OTP verification).
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
            'mobile_verified_at' => null,
        ]);
    }

    /**
     * Deactivated account.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
