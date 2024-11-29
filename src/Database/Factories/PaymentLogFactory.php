<?php

namespace Igniter\PayRegister\Database\Factories;

use Igniter\Flame\Database\Factories\Factory;

class PaymentLogFactory extends Factory
{
    protected $model = \Igniter\PayRegister\Models\PaymentLog::class;

    public function definition(): array
    {
        return [
            'message' => $this->faker->sentence,
            'payment_code' => $this->faker->word,
            'payment_name' => $this->faker->name,
            'is_success' => true,
            'request' => [],
            'response' => [],
            'is_refundable' => false,
        ];
    }
}
