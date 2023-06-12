<?php

namespace Igniter\PayRegister\Models\Observers;

use Igniter\PayRegister\Models\Payment;

class PaymentObserver
{
    public function retrieved(Payment $payment)
    {
        $payment->applyGatewayClass();

        if (is_array($payment->data)) {
            $payment->setRawAttributes(array_merge($payment->data, $payment->getAttributes()));
        }
    }

    public function saving(Payment $payment)
    {
        if (!$payment->exists) {
            return;
        }

        $payment->data = $payment->purgeConfigFields();
    }
}
