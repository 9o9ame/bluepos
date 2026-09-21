<?php

namespace Database\Factories;

use App\Enums\WarehouseStatus;
use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => Branch::query()->find($attributes['branch_id'])?->tenant_id,
            'branch_id' => Branch::factory(),
            'code' => strtoupper(fake()->unique()->bothify('WH##')),
            'name' => 'Warehouse '.fake()->word(),
            'status' => WarehouseStatus::Active,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_default' => true,
        ]);
    }
}
