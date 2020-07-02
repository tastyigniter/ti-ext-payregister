<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Admin\Models\Orders_model;
use ApplicationException;
use Exception;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Omnipay\Omnipay;
use Redirect;
use Session;

class Stripe extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    public function registerEntryPoints()
    {
        return [
            'stripe_return_url' => 'processReturnUrl',
        ];
    }

    public function getHiddenFields()
    {
        return [
            'stripe_payment_method' => '',
            'idempotency_key' => uniqid(),
        ];
    }

    public function isTestMode()
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getPublishableKey()
    {
        return $this->isTestMode() ? $this->model->test_publishable_key : $this->model->live_publishable_key;
    }

    public function getSecretKey()
    {
        return $this->isTestMode() ? $this->model->test_secret_key : $this->model->live_secret_key;
    }

    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * @param self $host
     * @param \Main\Classes\MainController $controller
     */
    public function beforeRenderPaymentForm($host, $controller)
    {
        $controller->addCss('$/igniter/payregister/assets/stripe.css', 'stripe-css');
        $controller->addJs('https://js.stripe.com/v3/', 'stripe-js');
        $controller->addJs('$/igniter/payregister/assets/process.stripe.js', 'process-stripe-js');
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @return bool|\Illuminate\Http\RedirectResponse
     * @throws \ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['paymentMethod'] = array_get($data, 'stripe_payment_method');
        $fields['idempotencyKey'] = array_get($data, 'idempotency_key');

        if (array_get($data, 'create_payment_profile', 0) == 1 AND $order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        }

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            if ($response->isRedirect()) {
                Session::put('ti_payregister_stripe_intent', $response->getPaymentIntentReference());

                return Redirect::to($response->getRedirectUrl());
            }

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
            if (!$hash OR !$order instanceof Orders_model)
                throw new ApplicationException('No order found');

            if (!strlen($redirectPage))
                throw new ApplicationException('No redirect page found');

            if (!strlen($cancelPage))
                throw new ApplicationException('No cancel page found');

            $paymentMethod = $order->payment_method;
            if (!$paymentMethod OR $paymentMethod->getGatewayClass() != static::class)
                throw new ApplicationException('No valid payment method found');

            $fields = $this->getPaymentFormFields($order);
            $fields['paymentIntentReference'] = Session::get('ti_payregister_stripe_intent');

            $gateway = $this->createGateway();
            $request = $gateway->completePurchase($fields);
            $response = $request->send();

            if (!$response->isSuccessful())
                throw new ApplicationException($response->getMessage());

            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
            $order->updateOrderStatus($paymentMethod->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();

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

    //
    // Payment Profiles
    //

    /**
     * {@inheritDoc}
     */
    public function supportsPaymentProfiles()
    {
        return TRUE;
    }

    /**
     * {@inheritDoc}
     */
    public function updatePaymentProfile($customer, $data)
    {
        return $this->handleUpdatePaymentProfile($customer, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function deletePaymentProfile($customer, $profile)
    {
        $this->handleDeletePaymentProfile($customer, $profile);
    }

    /**
     * {@inheritDoc}
     */
    public function payFromPaymentProfile($order, $data = [])
    {
        $host = $this->getHostObject();
        $profile = $host->findPaymentProfile($order->customer);

        if (!$profile OR !$profile->hasProfileData())
            throw new ApplicationException('Payment profile not found');

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['cardReference'] = array_get($profile->profile_data, 'card_id');
        $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        $fields['idempotencyKey'] = array_get($data, 'idempotency_key');

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            if ($response->isRedirect()) {
                Session::put('ti_payregister_stripe_intent', $response->getPaymentIntentReference());

                return Redirect::to($response->getRedirectUrl());
            }

            $this->handlePaymentResponse($response, $order, $host, $fields);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $customerId = array_get($profileData, 'customer_id');

        $response = FALSE;
        $gateway = $this->createGateway();
        $newCustomerRequired = !$customerId;

        if (!$newCustomerRequired) {
            $response = $gateway->fetchCustomer([
                'customerReference' => $customerId,
            ])->send();

            if ($response->isSuccessful()) {
                if (isset($responseData['deleted'])) {
                    $newCustomerRequired = TRUE;
                }
            }
            else {
                $newCustomerRequired = TRUE;
            }
        }

        if ($newCustomerRequired) {
            $response = $gateway->createCustomer([
                'name' => $customer->first_name.' '.$customer->last_name,
                'email' => $customer->email,
            ])->send();

            if (!$response->isSuccessful()) {
                throw new ApplicationException($response->getMessage());
            }
        }

        return $response;
    }

    protected function createOrFetchCard($customerId, $profileData, $data)
    {
        $cardId = array_get($profileData, 'card_id');
        $token = array_get($data, 'stripe_payment_method');

        $response = FALSE;
        $gateway = $this->createGateway();
        $newCardRequired = !$cardId;

        if (!$newCardRequired) {
            $response = $gateway->fetchCard([
                'cardReference' => $cardId,
                'customerReference' => $customerId,
            ])->send();

            if (!$response->isSuccessful())
                $newCardRequired = TRUE;
        }

        if ($newCardRequired) {
            $response = $gateway->attachCard([
                'customerReference' => $customerId,
                'paymentMethod' => $token,
            ])->send();

            if (!$response->isSuccessful())
                throw new ApplicationException($response->getMessage());
        }

        return $response;
    }

    /**
     * @param \Admin\Models\Payment_profiles_model $profile
     * @param array $profileData
     * @param array $cardData
     * @return \Admin\Models\Payment_profiles_model
     */
    protected function updatePaymentProfileData($profile, $profileData = [], $cardData = [])
    {
        $profile->card_brand = strtolower(array_get($cardData, 'card.brand'));
        $profile->card_last4 = array_get($cardData, 'card.last4');
        $profile->setProfileData($profileData);

        return $profile;
    }

    //
    //
    //

    /**
     * @return \Omnipay\Common\GatewayInterface|\Omnipay\Stripe\PaymentIntentsGateway
     */
    protected function createGateway()
    {
        $gateway = Omnipay::create('Stripe\PaymentIntents');

        $gateway->setApiKey($this->getSecretKey());

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $returnUrl = $this->makeEntryPointUrl('stripe_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'currency' => currency()->getUserCurrency(),
            'transactionId' => $order->order_id,
            'returnUrl' => $returnUrl,
            'confirm' => TRUE,
            'metadata' => [
                'order_id' => $order->order_id,
                'customer_email' => $order->email,
            ],
        ];

        $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, $data]);

        return $fields;
    }
}
