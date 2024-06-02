<?php

namespace Igniter\PayRegister\Database\Factories;

use Igniter\Flame\Database\Factories\Factory;

class PaymentFactory extends Factory
{

    protected $model = \Igniter\PayRegister\Models\Payment::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'code' => $this->faker->name,
            'class_name' => $this->faker->name,
            'description' => $this->faker->text,
            'data' => [],
            'priority' => $this->faker->randomNumber(),
            'status' => 1,
        ];
    }
}
