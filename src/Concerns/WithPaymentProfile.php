<?php

namespace Igniter\PayRegister\Concerns;

use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\SystemException;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\User\Models\Customer;

trait WithPaymentProfile
{
    /**
     * This method should return TRUE if the gateway supports user payment profiles.
     * The payment gateway must implement the updatePaymentProfile(), deletePaymentProfile() and payFromPaymentProfile() methods if this method returns true.
     */
    public function supportsPaymentProfiles(): bool
    {
        return false;
    }

    /**
     * Creates a customer profile on the payment gateway or update if the profile already exists.
     */
    public function updatePaymentProfile(Customer $customer, array $data = []): PaymentProfile
    {
        throw new SystemException('Please implement the captureAuthorizedPayment method on your custom payment class.');
    }

    /**
     * Deletes a customer payment profile from the payment gateway.
     */
    public function deletePaymentProfile($customer, PaymentProfile $profile)
    {
        throw new SystemException('Please implement the deletePaymentProfile method on your custom payment class.');
    }

    /**
     * Creates a payment transaction from an existing payment profile.
     */
    public function payFromPaymentProfile(Order $order, array $data = [])
    {
        throw new SystemException('Please implement the payFromPaymentProfile method on your custom payment class.');
    }
}