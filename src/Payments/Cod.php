<?php

namespace Igniter\PayRegister\Payments;

use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\BasePaymentGateway;

class Cod extends BasePaymentGateway
{
    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * @param array $data
     * @param \Igniter\PayRegister\Models\Payment $host
     * @param \Igniter\Cart\Models\Order $order
     *
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        if (!$paymentMethod = $order->payment) {
            throw new ApplicationException('Payment method not found');
        }

        if (!$this->isApplicable($order->order_total, $host)) {
            throw new ApplicationException(sprintf(
                lang('igniter.payregister::default.alert_min_order_total'),
                currency_format($host->order_total),
                $host->name
            ));
        }

        $order->updateOrderStatus($host->order_status, ['notify' => false]);
        $order->markAsPaymentProcessed();
    }
}
