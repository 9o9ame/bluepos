<?php

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('BR##')),
            'name' => fake()->city(),
            'status' => BranchStatus::Active,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'code' => 'MAIN',
            'name' => 'Main',
            'is_default' => true,
        ]);
    }
}
