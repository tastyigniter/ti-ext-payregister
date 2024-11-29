<?php

namespace Igniter\PayRegister\Database\Factories;

use Igniter\PayRegister\Models\PaymentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentProfileFactory extends Factory
{
    protected $model = PaymentProfile::class;

    public function definition(): array
    {
        return [
            'customer_id' => 1,
            'payment_id' => 1,
            'card_brand' => 1,
            'card_last4' => 1,
            'profile_data' => [],
            'is_primary' => false,
        ];
    }
}
