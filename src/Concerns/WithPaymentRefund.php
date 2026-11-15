<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Concerns;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Models\PaymentLog;
use LogicException;

trait WithPaymentRefund
{
    public function canRefundPayment(PaymentLog $paymentLog): bool
    {
        return (bool)$paymentLog->is_refundable;
    }

    public function processRefundForm(array $data, Order $order, PaymentLog $paymentLog)
    {
        throw new LogicException('Please implement the processRefundForm method on your custom payment class.');
    }

    /**
     * The amount the refund form asks to refund: the order total for a full refund,
     * otherwise the partial amount entered.
     */
    protected function getRefundAmount(Order $order, array $data): float
    {
        return array_get($data, 'refund_type') === 'full'
            ? (float)$order->order_total
            : (float)array_get($data, 'refund_amount');
    }
}
