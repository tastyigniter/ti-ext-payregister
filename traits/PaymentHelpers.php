<?php

namespace Igniter\PayRegister\Traits;

use Exception;
use Igniter\Flame\Exception\ApplicationException;

trait PaymentHelpers
{
    protected function validatePaymentMethod($order, $host)
    {
        $paymentMethod = $order->payment_method;
        if (!$paymentMethod OR $paymentMethod->code != $host->code)
            throw new ApplicationException('Payment method not found');

        if (!$this->isApplicable($order->order_total, $host))
            throw new ApplicationException(sprintf(
                lang('igniter.payregister::default.alert_min_order_total'),
                currency_format($host->order_total), $host->name
            ));
    }

    protected function handleUpdatePaymentProfile($customer, $data)
    {
        $profile = $this->model->findPaymentProfile($customer);
        $profileData = $profile ? (array)$profile->profile_data : [];

        $response = $this->createOrFetchCustomer($profileData, $customer);
        $customerId = $response->getCustomerReference();

        $response = $this->createOrFetchCard($customerId, $profileData, $data);
        $cardData = $response->getData();
        $cardId = $response->getCardReference();

        if (!$profile)
            $profile = $this->model->initPaymentProfile($customer);

        $this->updatePaymentProfileData($profile, [
            'customer_id' => $customerId,
            'card_id' => $cardId,
        ], $cardData);

        return $profile;
    }

    protected function handleDeletePaymentProfile($customer, $profile)
    {
        if (!isset($profile->profile_data['customer_id']))
            return;

        $this->createGateway()->deleteCustomer([
            'customerReference' => $profile->profile_data['customer_id'],
        ])->send();

        $this->updatePaymentProfileData($profile);
    }

    /**
     * @param \Omnipay\Common\Message\ResponseInterface $response
     * @param \Admin\Models\Orders_model $order
     * @param \Admin\Models\Payments_model $host
     * @param $fields
     * @return void
     * @throws \Exception
     */
    protected function handlePaymentResponse($response, $order, $host, $fields)
    {
        if ($response->isSuccessful()) {
            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
            $order->updateOrderStatus($host->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();
        }
        else {
            $order->logPaymentAttempt('Payment error -> '.$response->getMessage(), 0, $fields, $response->getData());
            throw new Exception($response->getMessage());
        }
    }
}