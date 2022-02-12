<?php

namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Admin\Models\Orders_model;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Illuminate\Support\Facades\Event;
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

    public function completesPaymentOnClient()
    {
        return TRUE;
    }

    public function getStripeJsOptions($order)
    {
        $options = [
            'locale' => app()->getLocale(),
        ];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendJsOptions', [$options, $order], FALSE);
        if (is_array($eventResult))
            $options = array_merge($options, ...array_filter($eventResult));

        return $options;
    }

    public function getStripeOptions()
    {
        $options = [];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendOptions', [$options], FALSE);
        if (is_array($eventResult))
            $options = array_merge($options, ...array_filter($eventResult));

        return $options;
    }

    public function createOrFetchIntent($order)
    {
        try {
            if ($order->isPaymentProcessed())
                return;

            $this->validatePaymentMethod($order, $this->model);

            if (!$response = $this->updatePaymentIntentSession($order)) {
                $fields = $this->getPaymentFormFields($order);
                $stripeOptions = $this->getStripeOptions();
                $response = $this->createGateway()->paymentIntents->create($fields, $stripeOptions);

                Session::put($this->sessionKey, $response->id);
            }

            return optional($response)->client_secret;
        }
        catch (Exception $ex) {
            flash()->warning($ex->getMessage())->important()->now();
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

            $gateway = $this->createGateway();
            $stripeOptions = $this->getStripeOptions();
            $paymentIntent = $gateway->paymentIntents->retrieve($intentId, [], $stripeOptions);

            // At this stage the PaymentIntent status should either
            // be succeeded or requires_capture, we will only attach
            // the PaymentIntent to a customer since updating the amount
            // of a PaymentIntent with a succeeded status is not allowed
            if (!in_array($paymentIntent->status, ['requires_capture', 'succeeded']))
                return TRUE;

            $fields = $this->getPaymentFormFields($order, $data, TRUE);

            if (array_get($data, 'create_payment_profile', 0) == 1 && $order->customer) {
                $data['stripe_payment_method'] = $paymentIntent->payment_method;
                $profile = $this->updatePaymentProfile($order->customer, $data);
                $fields['customer'] = array_get($profile->profile_data, 'customer_id');
            }

            $gateway->paymentIntents->update($paymentIntent->id, array_except($fields, [
                'amount', 'currency', 'capture_method', 'setup_future_usage',
            ]), $stripeOptions);

            if ($paymentIntent->status === 'requires_capture') {
                $order->logPaymentAttempt('Payment authorized', 1, $data, $paymentIntent->toArray());
            }
            else {
                $order->logPaymentAttempt('Payment successful', 1, $data, $paymentIntent->toArray(), TRUE);
            }

            $order->updateOrderStatus($host->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();

            Session::forget($this->sessionKey);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $data, $paymentIntent ?? []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function capturePaymentIntent($paymentIntentId, $order, $data = [])
    {
        if ($order->payment !== $this->model->code)
            return;

        try {
            $response = $this->createGateway()->paymentIntents->capture(
                $paymentIntentId,
                $this->getPaymentCaptureFields($order, $data),
                $this->getStripeOptions()
            );

            if ($response->status == 'succeeded') {
                $order->logPaymentAttempt('Payment captured successfully', 1, $data, $response);
            }
            else {
                $order->logPaymentAttempt('Payment captured failed', 0, $data, $response);
            }

            return $response;
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment capture failed -> '.$ex->getMessage(), 0, $data, $response);
        }
    }

    public function cancelPaymentIntent($paymentIntentId, $order, $data = [])
    {
        if ($order->payment !== $this->model->code)
            return;

        try {
            $response = $this->createGateway()->paymentIntents->cancel(
                $paymentIntentId, $data, $this->getStripeOptions()
            );

            if ($response->status == 'canceled') {
                $order->logPaymentAttempt('Payment canceled successfully', 1, $data, $response);
            }
            else {
                $order->logPaymentAttempt('Payment canceled failed', 0, $data, $response);
            }

            return $response;
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment canceled failed -> '.$ex->getMessage(), 0, $data, $response);
        }
    }

    public function updatePaymentIntentSession($order)
    {
        try {
            if ($intentId = Session::get($this->sessionKey)) {
                $gateway = $this->createGateway();
                $stripeOptions = $this->getStripeOptions();
                $paymentIntent = $gateway->paymentIntents->retrieve($intentId, [], $stripeOptions);

                // We can not update the amount of a PaymentIntent with one of the following statuses
                if (in_array($paymentIntent->status, ['requires_capture', 'succeeded']))
                    return $paymentIntent;

                $fields = $this->getPaymentFormFields($order, [], TRUE);
                $gateway->paymentIntents->update($paymentIntent->id, array_except($fields, [
                    'capture_method', 'setup_future_usage',
                ]), $stripeOptions);

                return $paymentIntent;
            }
        }
        catch (Exception $ex) {
            return FALSE;
        }
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

        if (!$profile || !$profile->hasProfileData())
            throw new ApplicationException('Payment profile not found');

        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();

        try {
            $fields = $this->getPaymentFormFields($order);
            $fields['customer'] = array_get($profile->profile_data, 'customer_id');
            $fields['payment_method'] = array_get($profile->profile_data, 'card_id');
            unset($fields['setup_future_usage']);
            $fields['off_session'] = TRUE;
            $fields['confirm'] = TRUE;

            $intent = $gateway->paymentIntents->create($fields, $stripeOptions);

            if ($intent->status !== 'succeeded')
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

    protected function handleUpdatePaymentProfile($customer, $data)
    {
        $profile = $this->model->findPaymentProfile($customer);
        $profileData = $profile ? (array)$profile->profile_data : [];

        $response = $this->createOrFetchCustomer($profileData, $customer);
        $customerId = $response->id;

        $response = $this->createOrFetchCard($customerId, $profileData, $data);
        $cardData = $response->toArray();
        $cardId = $response->id;

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

        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();
        $gateway->customers->delete($profile->profile_data['customer_id'], [], $stripeOptions);

        $this->updatePaymentProfileData($profile);
    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $customerId = array_get($profileData, 'customer_id');

        $response = FALSE;
        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();
        $newCustomerRequired = !$customerId;

        if (!$newCustomerRequired) {
            try {
                $response = $gateway->customers->retrieve($customerId, [], $stripeOptions);

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
                $response = $gateway->customers->create([
                    'name' => $customer->full_name,
                    'email' => $customer->email,
                ], $stripeOptions);
            }
        }
        catch (Exception $ex) {
            throw new ApplicationException($ex->getMessage());
        }

        return $response;
    }

    protected function createOrFetchCard($customerId, $profileData, $data)
    {
        $cardId = array_get($profileData, 'card_id');
        $token = array_get($data, 'stripe_payment_method');

        $response = FALSE;
        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();
        $newCardRequired = !$cardId;

        if (!$newCardRequired) {
            try {
                $response = $gateway->paymentMethods->retrieve($cardId, [], $stripeOptions);

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
                ], $stripeOptions);
            }
            catch (Exception $ex) {
                throw new ApplicationException($ex->getMessage());
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
        if (!is_null($paymentLog->refunded_at) || !is_array($paymentLog->response))
            throw new ApplicationException('Nothing to refund');

        if (!array_get($paymentLog->response, 'status') === 'succeeded'
            || !array_get($paymentLog->response, 'object') === 'payment_intent'
        ) throw new ApplicationException('No charge to refund');

        $paymentChargeId = array_get($paymentLog->response, 'id');
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        if ($refundAmount > $order->order_total)
            throw new ApplicationException('Refund amount should be be less than total');

        try {
            $gateway = $this->createGateway();
            $stripeOptions = $this->getStripeOptions();
            $fields = $this->getPaymentRefundFields($order, $data);
            $response = $gateway->refunds->create(array_merge($fields, [
                'payment_intent' => $paymentChargeId,
                'amount' => number_format($refundAmount, 2, '', ''),
            ]), $stripeOptions);

            if ($response->status === 'failed')
                throw new Exception('Refund failed');

            $message = sprintf('Payment intent %s refunded successfully -> (%s: %s)',
                $paymentChargeId,
                currency_format($refundAmount),
                array_get($response->toArray(), 'refunds.data.0.id')
            );

            $order->logPaymentAttempt($message, 1, $fields, $response->toArray());
            $paymentLog->markAsRefundProcessed();
        }
        catch (Exception $e) {
            $order->logPaymentAttempt('Refund failed -> '.$response->getMessage(), 0, $fields, $response->toArray());
        }
    }

    protected function getPaymentFormFields($order, $data = [], $updatingIntent = FALSE)
    {
        $fields = [
            'amount' => number_format($order->order_total, 2, '', ''),
            'currency' => currency()->getUserCurrency(),
            'capture_method' => $this->shouldAuthorizePayment() ? 'manual' : 'automatic',
            'metadata' => [
                'order_id' => $order->order_id,
            ],
        ];

        if ($this->supportsPaymentProfiles() && $order->customer)
            $fields['setup_future_usage'] = 'off_session';

        $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, $data, $updatingIntent]);

        return $fields;
    }

    protected function getPaymentCaptureFields($order, $fields = [])
    {
        $eventResult = $this->fireSystemEvent('payregister.stripe.extendCaptureFields', [$fields, $order], FALSE);
        if (is_array($eventResult))
            $fields = array_merge($fields, ...$eventResult);

        return $fields;
    }

    protected function getPaymentRefundFields($order, $data)
    {
        $fields = [];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendRefundFields', [$fields, $order, $data], FALSE);
        if (is_array($eventResult))
            $fields = array_merge($fields, ...$eventResult);

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

        $stripeClient = new StripeClient([
            'api_key' => $this->getSecretKey(),
        ]);

        $this->fireSystemEvent('payregister.stripe.extendClient', [$stripeClient]);

        return $stripeClient;
    }

    //
    // Webhook
    //

    public function processWebhookUrl()
    {
        if (strtolower(request()->method()) !== 'post')
            return response('Request method must be POST', 400);

        $payload = json_decode(request()->getContent(), TRUE);
        if (!isset($payload['type']) || !strlen($eventType = $payload['type']))
            return response('Missing webhook event name', 400);

        $eventName = Str::studly(str_replace('.', '_', $eventType));
        $methodName = 'webhookHandle'.$eventName;

        if (method_exists($this, $methodName))
            $this->$methodName($payload);

        Event::fire('payregister.stripe.webhook.handle'.$eventName, [$payload]);

        return response('Webhook Handled');
    }

    protected function webhookHandlePaymentIntentSucceeded($payload)
    {
        if ($order = Orders_model::find($payload['data']['object']['metadata']['order_id'])) {
            if (!$order->isPaymentProcessed()) {
                if ($payload['data']['object']['status'] === 'requires_capture') {
                    $order->logPaymentAttempt('Payment authorized', 1, [], $payload['data']['object']);
                }
                else {
                    $order->logPaymentAttempt('Payment successful', 1, [], $payload['data']['object'], TRUE);
                }

                $order->updateOrderStatus($this->model->order_status, ['notify' => FALSE]);
                $order->markAsPaymentProcessed();
            }
        }
    }
}
