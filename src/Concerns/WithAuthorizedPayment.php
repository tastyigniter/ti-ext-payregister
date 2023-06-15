<?php

namespace Igniter\PayRegister\Concerns;

use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\SystemException;

trait WithAuthorizedPayment
{
    public function shouldAuthorizePayment()
    {
        throw new SystemException('Please implement the shouldAuthorizePayment method on your custom payment class.');
    }

    public function shouldCapturePayment(Order $order): bool
    {
        return $this->model->capture_status == $order->status_id;
    }

    public function captureAuthorizedPayment(Order $order)
    {
        throw new SystemException('Please implement the captureAuthorizedPayment method on your custom payment class.');
    }
}