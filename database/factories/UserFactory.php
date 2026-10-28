<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @var class-string<User>
     */
    protected $model = User::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->unique()->numerify('9#########'),
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
            'date_of_birth' => '1975-06-15',
            'gender' => 'M',
            // Verified by default (2026-10-27): a fixture that is not about identity
            // signs in as an officer who completed it. `unverifiedIdentity()` for
            // one that is.
            'identity_verified_at' => now(),
            'identity_verification_method' => 'digilocker_simulated',
        ];
    }

    /**
     * Identity not yet verified (2026-10-27).
     */
    public function unverifiedIdentity(): static
    {
        return $this->state(fn (array $attributes): array => [
            'identity_verified_at' => null,
            'identity_verification_method' => null,
        ]);
    }

    /**
     * Post this account to a department in a role (change item 2).
     *
     * **This replaces `create(['department_id' => …])`, which no longer exists.**
     * A user does not carry a department; they hold a dated assignment that
     * carries one, and the assignment is also what grants the role — so this one
     * call replaces the create-then-`assignRole()` pair every fixture used to
     * write, and cannot produce the half-state where one was done and the other
     * forgotten.
     *
     * It goes through `DepartmentAssignmentService` rather than inserting a row,
     * on purpose: a fixture that builds its assignment by hand is a fixture that
     * stops resembling production the first time the service gains a rule.
     *
     * Accepts an id as readily as a model, because most fixtures have the id
     * already.
     */
    public function assignedTo(Department|int $department, Role $role = Role::AuthorizedOfficer): static
    {
        return $this->afterCreating(function (User $user) use ($department, $role): void {
            app(DepartmentAssignmentService::class)->assign(
                $user,
                $department instanceof Department ? $department : Department::query()->findOrFail($department),
                $role,
                remarks: 'Test fixture.',
            );
        });
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
