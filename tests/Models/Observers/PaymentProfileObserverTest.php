<?php

namespace Igniter\PayRegister\Tests\Models\Observers;

use Igniter\PayRegister\Models\Observers\PaymentProfileObserver;
use Igniter\PayRegister\Models\PaymentProfile;

it('mark as primary after saved', function() {
    $paymentProfile = PaymentProfile::factory()->create(['is_primary' => false]);

    $paymentProfile->is_primary = true;
    $paymentProfile->save();

    $observer = new PaymentProfileObserver();
    $observer->saved($paymentProfile);

    expect($paymentProfile->is_primary)->toBeTrue();
});
