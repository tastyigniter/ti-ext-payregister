<?php

namespace Igniter\PayRegister\Tests\Payments\Fixtures;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithAuthorizedPayment;

class TestPaymentWithAuthorized extends BasePaymentGateway
{
    use WithAuthorizedPayment;

    public function defineFieldsConfig()
    {
        return __DIR__.'/../../_fixtures/fields';
    }

    public function shouldAuthorizePayment()
    {
        return true;
    }

    public function captureAuthorizedPayment(Order $order)
    {
        return true;
    }

    public function cancelAuthorizedPayment(Order $order)
    {
        return true;
    }
}
