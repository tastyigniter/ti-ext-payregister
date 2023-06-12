<?php

namespace Igniter\PayRegister\Listeners;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Models\Payment;

class UpdatePaymentIntentSessionOnCheckout
{
    public function handle(Order $order)
    {
        if (!$order->payment_method || !$order->payment_method instanceof Payment) {
            return;
        }

        if (!$order->payment_method->methodExists('updatePaymentIntentSession')) {
            return;
        }

        $order->payment_method->updatePaymentIntentSession($order);
    }
}
