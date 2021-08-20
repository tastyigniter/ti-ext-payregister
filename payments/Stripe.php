<?php

namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Admin\Models\Orders_model;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Omnipay\Common\Http\Client;
use Omnipay\Omnipay;

class Stripe extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    public function registerEntryPoints()
    {
        return [
            'stripe_return_url' => 'processReturnUrl',
            'stripe_webhook' => 'processWebhookUrl',
        ];
    }

    public function getHiddenFields()
    {
        return [
            'stripe_payment_method' => '',
            'stripe_idempotency_key' => uniqid('', TRUE),
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

    public function shouldAuthorizePayment()
    {
        return $this->model->transaction_type == 'auth_only';
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
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['paymentMethod'] = array_get($data, 'stripe_payment_method');

        if (array_get($data, 'create_payment_profile', 0) == 1 AND $order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        }

        try {
            $response = $this->createPurchaseRequest($fields, $data)->send();

            if ($response->isRedirect()) {
                Session::put('ti_payregister_stripe_intent', $response->getPaymentIntentReference());

                return Redirect::to($response->getRedirectUrl());
            }

            $this->handlePaymentResponse($response, $order, $host, $fields, TRUE);
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

            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData(), TRUE);
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
     * {@inheritdoc}
     */
    public function supportsPaymentProfiles()
    {
        return TRUE;
    }

    /**
     * {@inheritdoc}
     */
    public function updatePaymentProfile($customer, $data)
    {
        return $this->handleUpdatePaymentProfile($customer, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function deletePaymentProfile($customer, $profile)
    {
        $this->handleDeletePaymentProfile($customer, $profile);
    }

    /**
     * {@inheritdoc}
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
        $fields['idempotencyKey'] = array_get($data, 'stripe_idempotency_key');

        try {
            $response = $this->createPurchaseRequest($fields, $data)->send();

            if ($response->isRedirect()) {
                Session::put('ti_payregister_stripe_intent', $response->getPaymentIntentReference());

                return Redirect::to($response->getRedirectUrl());
            }

            $this->handlePaymentResponse($response, $order, $host, $fields, TRUE);
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

    public function processRefundForm($data, $order, $paymentLog)
    {
        if (!is_null($paymentLog->refunded_at) OR !is_array($paymentLog->response))
            throw new ApplicationException('Nothing to refund');

        if (!array_get($paymentLog->response, 'status') === 'succeeded'
            OR !array_get($paymentLog->response, 'object') === 'payment_intent'
        ) throw new ApplicationException('No charge to refund');

        $paymentChargeId = array_get($paymentLog->response, 'charges.data.0.id');
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        if ($refundAmount > $order->order_total)
            throw new ApplicationException('Refund amount should be be less than total');

        $fields = [
            'transactionReference' => $paymentChargeId,
            'amount' => number_format($refundAmount, 2, '.', ''),
        ];

        $gateway = $this->createGateway();
        $response = $gateway->refund($fields)->send();

        if ($response->isSuccessful()) {
            $message = sprintf('Charge %s refunded successfully -> (%s: %s)',
                $paymentChargeId,
                currency_format($refundAmount),
                array_get($response->getData(), 'refunds.data.0.id')
            );

            $order->logPaymentAttempt($message, 1, $fields, $response->getData());
            $paymentLog->markAsRefundProcessed();

            return;
        }

        $order->logPaymentAttempt('Refund failed -> '.$response->getMessage(), 0, $fields, $response->getData());
    }

    /**
     * @return \Omnipay\Common\GatewayInterface|\Omnipay\Stripe\PaymentIntentsGateway
     */
    protected function createGateway()
    {
        $gateway = Omnipay::create('Stripe\PaymentIntents', $this->createHttpClient());

        $gateway->setApiKey($this->getSecretKey());
        $gateway->setStripeVersion('2020-08-27');

        return $gateway;
    }

    protected function createHttpClient()
    {
        $userAgent = [
            'lang' => 'php',
            'lang_version' => PHP_VERSION,
            'publisher' => 'TastyIgniter',
            'uname' => php_uname(),
            'application' => [
                'name' => 'TastyIgniter Stripe',
                'partner_id' => 'pp_partner_JZyCCGR3cOwj9S', // Used by Stripe to identify this integration
                'url' => 'https://tastyigniter.com/marketplace/item/igniter-payregister',
            ],
        ];

        $appInfo = $userAgent['application'];

        return new Client(\Http\Adapter\Guzzle6\Client::createWithConfig([
            'headers' => [
                'User-Agent' => sprintf('%s (%s)', $appInfo['name'], $appInfo['url']),
                'X-Stripe-Client-User-Agent' => json_encode($userAgent),
            ],
        ]));
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
            'receipt_email' => $order->email,
            'confirm' => TRUE,
            'metadata' => [
                'order_id' => $order->order_id,
                'customer_email' => $order->email,
            ],
        ];

        $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, $data]);

        return $fields;
    }

    protected function createPurchaseRequest(array $fields, array $data)
    {
        $method = $this->shouldAuthorizePayment() ? 'authorize' : 'purchase';
        $request = $this->createGateway()->$method($fields);
        $request->setIdempotencyKeyHeader(array_get($data, 'stripe_idempotency_key'));

        return $request;
    }

    //
    // Webhook
    //

    public function processWebhookUrl()
    {
        if (strtolower(request()->method()) !== 'post')
            return response('Request method must be POST', 400);

        $payload = json_decode(request()->getContent(), TRUE);
        if (!isset($payload['type']) OR !strlen($eventType = $payload['type']))
            return response('Missing webhook event name', 400);

        $eventName = 'handle'.Str::studly(str_replace('.', '_', $eventType));

        Event::fire('payregister.stripe.'.$eventName, [$payload]);

        return response('Webhook Handled');
    }
}
