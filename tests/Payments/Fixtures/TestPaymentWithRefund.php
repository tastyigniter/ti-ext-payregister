<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Tests\Payments\Fixtures;

use Override;
use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Igniter\PayRegister\Models\PaymentLog;

class TestPaymentWithRefund extends BasePaymentGateway
{
    use WithPaymentRefund;

    #[Override]
    public function defineFieldsConfig(): string
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
