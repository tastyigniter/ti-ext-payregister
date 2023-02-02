<?php

namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Admin\Models\Orders_model;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Omnipay\Omnipay;

class Mollie extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    public function registerEntryPoints()
    {
        return [
            'mollie_return_url' => 'processReturnUrl',
            'mollie_notify_url' => 'processNotifyUrl',
        ];
    }

    public function isTestMode()
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getApiKey()
    {
        return $this->isTestMode() ? $this->model->test_api_key : $this->model->live_api_key;
    }

    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @return bool|\Illuminate\Http\RedirectResponse
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        if ($order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        }

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            if ($response->isRedirect())
                return Redirect::to($response->getRedirectUrl());

            $this->handlePaymentResponse($response, $order, $host, $fields);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function processReturnUrl($params)
    {
        $hash = $params[0] ?? null;
        $redirectPage = input('redirect');
        $cancelPage = input('cancel');

        $order = $this->createOrderModel()->whereHash($hash)->first();

        try {
            if (!$hash || !$order instanceof Orders_model)
                throw new ApplicationException('No order found');

            if (!strlen($redirectPage))
                throw new ApplicationException('No redirect page found');

            if (!strlen($cancelPage))
                throw new ApplicationException('No cancel page found');

            $paymentMethod = $order->payment_method;
            if (!$paymentMethod || $paymentMethod->getGatewayClass() != static::class)
                throw new ApplicationException('No valid payment method found');

            if (!$order->isPaymentProcessed())
                throw new ApplicationException('Sorry, your payment was not successful. Please contact your bank or try again later.');

            return Redirect::to(page_url($redirectPage, [
                'id' => $order->getKey(),
                'hash' => $order->hash,
            ]));
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, [], []);
            flash()->warning($ex->getMessage())->important();
        }

        return Redirect::to(page_url($cancelPage));
    }

    public function processNotifyUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash || !$order instanceof Orders_model)
            return Response::json(['error' => 'No order found']);

        $paymentMethod = $order->payment_method;
        if (!$paymentMethod || $paymentMethod->getGatewayClass() != static::class)
            return Response::json(['error' => 'No valid payment method found']);

        $gateway = $this->createGateway();
        $fields = $this->getPaymentFormFields($order);
        $response = $gateway->completePurchase($fields)->send();

        if (!$order->isPaymentProcessed()) {
            if ($response->isPaid()) {
                $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
                $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
                $order->markAsPaymentProcessed();
            }
            else {
                $order->logPaymentAttempt('Payment unsuccessful', 0, $fields, $response->getData());
                $order->updateOrderStatus(setting('canceled_order_status'), ['notify' => false]);
            }
        }

        return Response::json(['success' => true]);
    }

    //
    // Payment Profiles
    //

    /**
     * {@inheritdoc}
     */
    public function updatePaymentProfile($customer, $data)
    {
        $profile = $this->model->findPaymentProfile($customer);
        $profileData = $profile ? (array)$profile->profile_data : [];

        $response = $this->createOrFetchCustomer($profileData, $customer);
        $customerId = $response->getCustomerReference();

        if (!$profile)
            $profile = $this->model->initPaymentProfile($customer);

        $profile->setProfileData([
            'customer_id' => $customerId,
            'card_id' => str_random(16),
        ]);

        return $profile;
    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $response = false;
        $newCustomerRequired = !array_get($profileData, 'customer_id');
        $gateway = $this->createGateway();

        if (!$newCustomerRequired) {
            $response = $gateway->fetchCustomer([
                'customerReference' => array_get($profileData, 'customer_id'),
            ])->send();

            if (!$response->isSuccessful())
                $newCustomerRequired = true;
        }

        if ($newCustomerRequired) {
            $response = $gateway->createCustomer([
                'firstName' => $customer->first_name,
                'lastName' => $customer->last_name,
                'email' => $customer->email,
            ])->send();

            if (!$response->isSuccessful()) {
                throw new ApplicationException($response->getMessage());
            }
        }

        return $response;
    }

    //
    //
    //

    /**
     * @return \Omnipay\Common\GatewayInterface|\Omnipay\Mollie\Gateway
     */
    protected function createGateway()
    {
        $gateway = Omnipay::create('Mollie');

        $gateway->setApiKey($this->getApiKey());

        $this->fireSystemEvent('payregister.mollie.extendGateway', [$gateway]);

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $notifyUrl = $this->makeEntryPointUrl('mollie_notify_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('mollie_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'currency' => currency()->getUserCurrency(),
            'description' => 'Payment for Order '.$order->order_id,
            'metadata' => [
                'order_id' => $order->order_id,
            ],
            'returnUrl' => $returnUrl,
            'notifyUrl' => $notifyUrl,
        ];

        $this->fireSystemEvent('payregister.mollie.extendFields', [&$fields, $order, $data]);

        return $fields;
    }
}
