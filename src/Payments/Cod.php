<?php

namespace Igniter\PayRegister\Payments;

use Igniter\PayRegister\Classes\BasePaymentGateway;

class Cod extends BasePaymentGateway
{
    public function defineFieldsConfig()
    {
        return 'igniter.payregister::/models/cod';
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
        $this->validateApplicableFee($order, $host);

        $order->updateOrderStatus($host->order_status, ['notify' => false]);
        $order->markAsPaymentProcessed();
    }
}
