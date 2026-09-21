<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
            'is_owner' => false,
            'username' => fake()->unique()->userName(),
            'security_version' => 1,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['is_owner' => true]);
    }
}
