<?php

namespace Igniter\PayRegister\Tests\Payments\Fixtures;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Igniter\PayRegister\Models\PaymentLog;

class TestPaymentWithRefund extends BasePaymentGateway
{
    use WithPaymentRefund;

    public function defineFieldsConfig()
    {
        return __DIR__.'/../../_fixtures/fields';
    }

    public function canRefundPayment(PaymentLog $paymentLog): bool
    {
        return true;
    }

    public function processRefundForm(array $data, Order $order, PaymentLog $paymentLog): bool
    {
        return true;
    }
}
