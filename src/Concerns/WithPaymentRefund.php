<?php

namespace Igniter\PayRegister\Concerns;

use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\SystemException;
use Igniter\PayRegister\Models\PaymentLog;

trait WithPaymentRefund
{
    public function canRefundPayment(PaymentLog $paymentLog)
    {
        return (bool)$paymentLog->is_refundable;
    }

    public function processRefundForm(array $data, Order $order, PaymentLog $paymentLog)
    {
        throw new SystemException('Please implement the processRefundForm method on your custom payment class.');
    }
}