<?php namespace SamPoyigi\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Admin\Models\Statuses_model;
use ApplicationException;
use Cart;

class Cod extends BasePaymentGateway
{
    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @throws \ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        if (!$paymentMethod = $order->payment)
            throw new ApplicationException('Payment method not found');

        if (!$this->isApplicable($order->order_total, $host))
            throw new ApplicationException(sprintf(
                lang('sampoyigi.payregister::default.alert_min_order_total'),
                currency_format($host->order_total),
                $host->name
            ));

        if ($order->markAsPaymentProcessed()) {
            $order->updateOrderStatus($host->order_status);
        }
    }
}