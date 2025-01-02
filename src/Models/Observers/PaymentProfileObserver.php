<?php

namespace Igniter\PayRegister\Models\Observers;

use Igniter\PayRegister\Models\PaymentProfile;

class PaymentProfileObserver
{
    public function saved(PaymentProfile $paymentProfile)
    {
        if ($paymentProfile->is_primary && $paymentProfile->wasChanged('is_primary')) {
            $paymentProfile->makePrimary();
        }
    }
}
