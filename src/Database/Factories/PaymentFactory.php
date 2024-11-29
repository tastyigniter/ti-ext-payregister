<?php

namespace Igniter\PayRegister\Database\Factories;

use Igniter\Flame\Database\Factories\Factory;
use Igniter\PayRegister\Tests\Payments\Fixtures\TestPayment;

class PaymentFactory extends Factory
{
    protected $model = \Igniter\PayRegister\Models\Payment::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'code' => $this->faker->word,
            'class_name' => TestPayment::class,
            'description' => $this->faker->text,
            'data' => [],
            'priority' => $this->faker->randomNumber(),
            'status' => 1,
        ];
    }
}
