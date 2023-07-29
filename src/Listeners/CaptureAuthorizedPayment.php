<?php

namespace Igniter\PayRegister\Listeners;

use Igniter\Admin\Models\StatusHistory;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Concerns\WithAuthorizedPayment;

class CaptureAuthorizedPayment
{
    public function handle(Model $order, StatusHistory $statusHistory)
    {
        if (!$order instanceof Order) {
            return;
        }

        throw_unless(
            $paymentMethod = $order->payment_method,
            new ApplicationException('No valid payment method found')
        );

        if (!in_array(WithAuthorizedPayment::class, class_uses($paymentMethod->getGatewayClass()))) {
            return;
        }

        if (!$paymentMethod->shouldCapturePayment($order)) {
            return;
        }

        $paymentMethod->captureAuthorizedPayment($order);
    }
}