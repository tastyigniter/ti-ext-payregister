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
use Stripe\StripeClient;

class Stripe extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    protected $sessionKey = 'ti_payregister_stripe_intent';

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
            'pay_from_payment_button' => 0,
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
     * Processes payment from a payment button
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @return bool|\Illuminate\Http\RedirectResponse
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentButton($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        try {
            if (!$intentId = Session::get($this->sessionKey))
                throw new Exception('Missing payment intent identifier in session.');

            $fields = $this->getPaymentFormFields($order, $data);

            $paymentIntent = $this->handlePaymentIntent($intentId, $fields, $data, $host, $order);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $data, $paymentIntent ?? []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
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

        try {
            if (!$intentId = Session::get($this->sessionKey))
                throw new Exception('Missing payment intent identifier in session.');

            $fields = $this->getPaymentFormFields($order, $data);
            if (array_get($data, 'create_payment_profile', 0) == 1 AND $order->customer) {
                $profile = $this->updatePaymentProfile($order->customer, $data);
                $fields['customer'] = array_get($profile->profile_data, 'customer_id');
            }

            $paymentIntent = $this->handlePaymentIntent($intentId, $fields, $data, $host, $order);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $data, $paymentIntent ?? []);
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

            if (!$intentId = Session::get($this->sessionKey))
                throw new ApplicationException('No valid payment method found');

            $fields = $this->getPaymentFormFields($order);

            $this->handlePaymentIntent($intentId, $fields, [], $paymentMethod, $order);

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

    public function fetchOrCreateIntent($order)
    {
        $fields = $this->getPaymentFormFields($order, FALSE);

        $response = null;
        $gateway = $this->createGateway();

        if ($intentId = Session::get($this->sessionKey)) {
            try {
                $response = $gateway->paymentIntents->update($intentId, $fields);
            }
            catch (Exception $ex) {
            }
        }

        try {
            $this->validatePaymentMethod($order);

            if (!$response OR !$intentId) {
                $response = $gateway->paymentIntents->create($fields);

                Session::put($this->sessionKey, $response->id);
            }
        }
        catch (Exception $ex) {
            flash()->warning($ex->getMessage())->important()->now();
        }

        return optional($response)->client_secret;
    }

    protected function handlePaymentIntent($intentId, $fields, $data, $host, $order)
    {
        $gateway = $this->createGateway();

        $paymentIntent = $gateway->paymentIntents->retrieve($intentId);

        $gateway->paymentIntents->update($paymentIntent->id, $fields);

        if ($paymentIntent->status !== 'succeeded')
            throw new Exception('Payment returned a failed status: '.$paymentIntent->status);

        $order->logPaymentAttempt('Payment successful', 1, $data, $paymentIntent, TRUE);
        $order->updateOrderStatus($host->order_status, ['notify' => FALSE]);
        $order->markAsPaymentProcessed();

        return $paymentIntent;
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

        $gateway = $this->createGateway();

        try {
            $fields = $this->getPaymentFormFields($order, $data);
            $fields['customer'] = array_get($profile->profile_data, 'customer_id');
            $fields['payment_method'] = array_get($profile->profile_data, 'card_id');
            $fields['off_session'] = TRUE;

            $intent = $gateway->paymentIntents->create($fields);

            if (!$intent->status !== 'succeeded')
                throw new Exception('Status '.$intent->status);

            $order->logPaymentAttempt('Payment successful', 1, $fields, $intent, TRUE);
            $order->updateOrderStatus($host->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();
        }
        catch (Exception $e) {
            $order->logPaymentAttempt('Payment error -> '.$e->getMessage(), 0, $data, $intent ?? []);
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
            try {
                $response = $gateway->customers->retrieve($customerId);

                if (isset($response->deleted)) {
                    $newCustomerRequired = TRUE;
                }
            }
            catch (Exception $e) {
                $newCustomerRequired = TRUE;
            }
        }

        try {
            if ($newCustomerRequired) {
                $response = $gateway->create([
                    'name' => $customer->full_name,
                    'email' => $customer->email,
                ]);
            }
        }
        catch (Exception $e) {
            throw new ApplicationException($e->getMessage());
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
            try {
                $response = $gateway->paymentMethods->retrieve($cardId);

                if ($response->customer != $customerId)
                    $newCardRequired = TRUE;
            }
            catch (Exception $e) {
                $newCardRequired = TRUE;
            }
        }

        if ($newCardRequired) {
            try {
                $response = $gateway->paymentMethods->attach($token, [
                    'customer' => $customerId,
                ])->send();
            }
            catch (Exception $e) {
                throw new ApplicationException($response->getMessage());
            }
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

        $paymentChargeId = array_get($paymentLog->response, 'id');
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        if ($refundAmount > $order->order_total)
            throw new ApplicationException('Refund amount should be be less than total');

        try {
            $gateway = $this->createGateway();
            $fields = [
                'payment_intent' => $paymentChargeId,
                'amount' => number_format($refundAmount, 2, '', ''),
            ];
            $response = $gateway->refund($fields);

            if ($response->status === 'failed')
                throw new Exception('Refund failed');

            $message = sprintf('Payment intent %s refunded successfully -> (%s: %s)',
                $paymentChargeId,
                currency_format($refundAmount),
                array_get($response->getData(), 'refunds.data.0.id')
            );

            $order->logPaymentAttempt($message, 1, $fields, $response->getData());
            $paymentLog->markAsRefundProcessed();
        }
        catch (Exception $e) {
            $order->logPaymentAttempt('Refund failed -> '.$response->getMessage(), 0, $fields, $response->getData());
        }
    }

    protected function getPaymentFormFields($order, $data = [], $allFields = TRUE)
    {
        $returnUrl = $this->makeEntryPointUrl('stripe_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'amount' => number_format($order->order_total, 2, '', ''),
            'currency' => currency()->getUserCurrency(),
            'return_url' => $returnUrl,
            'capture_method' => $this->shouldAuthorizePayment() ? 'automatic' : 'manual',
        ];

        if ($allFields) {
            $fields = array_merge_recursive($fields, [
                'confirm' => TRUE,
                'receipt_email' => $order->email,
                'metadata' => [
                    'order_id' => $order->order_id,
                    'customer_email' => $order->email,
                ],
            ]);

            $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, $data]);
        }

        return $fields;
    }

    protected function createGateway()
    {
        \Stripe\Stripe::setAppInfo(
            'TastyIgniter Stripe',
            '1.0.0',
            'https://tastyigniter.com/marketplace/item/igniter-payregister',
            'pp_partner_JZyCCGR3cOwj9S' // Used by Stripe to identify this integration
        );

        return new StripeClient([
            'api_key' => $this->getSecretKey(),
        ]);
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
