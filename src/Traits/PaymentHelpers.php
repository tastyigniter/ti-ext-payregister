<?php

namespace Igniter\PayRegister\Traits;

use Exception;

trait PaymentHelpers
{
    //
    //    protected function handleUpdatePaymentProfile($customer, $data)
    //    {
    //        $profile = $this->model->findPaymentProfile($customer);
    //        $profileData = $profile ? (array)$profile->profile_data : [];
    //
    //        $response = $this->createOrFetchCustomer($profileData, $customer);
    //        $customerId = $response->getCustomerReference();
    //
    //        $response = $this->createOrFetchCard($customerId, $profileData, $data);
    //        $cardData = $response->getData();
    //        $cardId = $response->getCardReference();
    //
    //        if (!$profile) {
    //            $profile = $this->model->initPaymentProfile($customer);
    //        }
    //
    //        $this->updatePaymentProfileData($profile, [
    //            'customer_id' => $customerId,
    //            'card_id' => $cardId,
    //        ], $cardData);
    //
    //        return $profile;
    //    }
    //
    //    protected function handleDeletePaymentProfile($customer, $profile)
    //    {
    //        if (!isset($profile->profile_data['customer_id'])) {
    //            return;
    //        }
    //
    //        $this->createGateway()->deleteCustomer([
    //            'customerReference' => $profile->profile_data['customer_id'],
    //        ])->send();
    //
    //        $this->updatePaymentProfileData($profile);
    //    }

    /**
     * @param \Omnipay\Common\Message\ResponseInterface $response
     * @param \Igniter\Cart\Models\Order $order
     * @param \Igniter\PayRegister\Models\Payment $host
     * @return void
     * @throws \Exception
     */
    protected function handlePaymentResponse($response, $order, $host, $fields, $isRefundable = false)
    {
        if ($response->isSuccessful()) {
            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData(), $isRefundable);
            $order->updateOrderStatus($host->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();
        } else {
            $order->logPaymentAttempt('Payment error -> '.$response->getMessage(), 0, $fields, $response->getData());

            throw new Exception($response->getMessage());
        }
    }
}
