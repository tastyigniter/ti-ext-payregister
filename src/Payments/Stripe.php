<?php

namespace Igniter\PayRegister\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithAuthorizedPayment;
use Igniter\PayRegister\Concerns\WithPaymentProfile;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\User\Models\Customer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class Stripe extends BasePaymentGateway
{
    use WithAuthorizedPayment;
    use WithPaymentProfile;
    use WithPaymentRefund;

    protected $sessionKey = 'ti_payregister_stripe_intent';

    public function defineFieldsConfig()
    {
        return 'igniter.payregister::/models/stripe';
    }

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
            'stripe_idempotency_key' => uniqid('', true),
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

    public function getWebhookSecret()
    {
        return $this->isTestMode() ? $this->model->test_webhook_secret : $this->model->live_webhook_secret;
    }

    /**
     * @param self $host
     * @param \Igniter\Main\Classes\MainController $controller
     */
    public function beforeRenderPaymentForm($host, $controller)
    {
        $controller->addCss('igniter.payregister::/js/stripe.css', 'stripe-css');
        $controller->addJs('https://js.stripe.com/v3/', 'stripe-js');
        $controller->addJs('igniter.payregister::/js/process.stripe.js', 'process-stripe-js');
    }

    public function completesPaymentOnClient()
    {
        return true;
    }

    public function getStripeJsOptions($order)
    {
        $options = [
            'locale' => $this->model->locale_code ?? app()->getLocale(),
        ];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendJsOptions', [$options, $order], false);
        if (is_array($eventResult)) {
            $options = array_merge($options, ...array_filter($eventResult));
        }

        return $options;
    }

    public function getStripeOptions()
    {
        $options = [];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendOptions', [$options], false);
        if (is_array($eventResult)) {
            $options = array_merge($options, ...array_filter($eventResult));
        }

        return $options;
    }

    public function createOrFetchIntent($order)
    {
        try {
            if ($order->isPaymentProcessed()) {
                return;
            }

            $this->validateApplicableFee($order, $this->model);

            $response = $this->updatePaymentIntentSession($order);

            if (!$response || in_array($response->status, ['requires_capture', 'succeeded'])) {
                $fields = $this->getPaymentFormFields($order);
                $stripeOptions = $this->getStripeOptions();
                $response = $this->createGateway()->paymentIntents->create($fields, $stripeOptions);

                Session::put($this->sessionKey, $response->id);
            }

            return optional($response)->client_secret;
        } catch (Exception $ex) {
            flash()->warning($ex->getMessage())->important()->now();
        }
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Igniter\PayRegister\Models\Payment $host
     * @param \Igniter\Cart\Models\Order $order
     *
     * @return bool|\Illuminate\Http\RedirectResponse
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validateApplicableFee($order, $host);

        try {
            if (!$intentId = Session::get($this->sessionKey)) {
                throw new Exception('Missing payment intent identifier in session.');
            }

            $gateway = $this->createGateway();
            $stripeOptions = $this->getStripeOptions();
            $paymentIntent = $gateway->paymentIntents->retrieve($intentId, [], $stripeOptions);

            // At this stage the PaymentIntent status should either
            // be succeeded or requires_capture, we will only attach
            // the PaymentIntent to a customer since updating the amount
            // of a PaymentIntent with a succeeded status is not allowed
            if (!in_array($paymentIntent->status, ['requires_capture', 'succeeded'])) {
                return true;
            }

            $fields = $this->getPaymentFormFields($order, $data, true);

            if (array_get($data, 'create_payment_profile', 0) == 1 && $order->customer) {
                $data['stripe_payment_method'] = $paymentIntent->payment_method;
                $profile = $this->updatePaymentProfile($order->customer, $data);
                $fields['customer'] = array_get($profile->profile_data, 'customer_id');
            }

            $gateway->paymentIntents->update($paymentIntent->id, array_except($fields, [
                'amount', 'currency', 'capture_method', 'setup_future_usage',
            ]), $stripeOptions);

            // Avoid logging payment and updating the order status more than once
            // For cases where the webhook is triggered before the user is redirected
            if ($order->isPaymentProcessed()) {
                return true;
            }

            if ($paymentIntent->status === 'requires_capture') {
                $order->logPaymentAttempt('Payment authorized', 1, $data, $paymentIntent->toArray());
            } else {
                $order->logPaymentAttempt('Payment successful', 1, $data, $paymentIntent->toArray(), true);
            }

            $order->updateOrderStatus($host->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();

            Session::forget($this->sessionKey);
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $data, $paymentIntent ?? []);

            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function captureAuthorizedPayment(Order $order)
    {
        throw_unless(
            $paymentLog = $order->payment_logs()->firstWhere('is_success', true),
            new ApplicationException('No successful payment to capture')
        );

        throw_unless(
            $paymentIntentId = array_get($paymentLog->response, 'id'),
            new ApplicationException('Missing payment intent ID in successful payment response')
        );

        return $this->capturePaymentIntent($paymentIntentId, $order);
    }

    public function capturePaymentIntent($paymentIntentId, $order, $data = [])
    {
        if ($order->payment !== $this->model->code) {
            return;
        }

        try {
            $response = $this->createGateway()->paymentIntents->capture(
                $paymentIntentId,
                $this->getPaymentCaptureFields($order, $data),
                $this->getStripeOptions()
            );

            if ($response->status == 'succeeded') {
                $order->logPaymentAttempt('Payment captured successfully', 1, $data, $response);
            } else {
                $order->logPaymentAttempt('Payment captured failed', 0, $data, $response);
            }

            return $response;
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment capture failed -> '.$ex->getMessage(), 0, $data, $response);
        }
    }

    public function cancelPaymentIntent($paymentIntentId, $order, $data = [])
    {
        if ($order->payment !== $this->model->code) {
            return;
        }

        try {
            $response = $this->createGateway()->paymentIntents->cancel(
                $paymentIntentId, $data, $this->getStripeOptions()
            );

            if ($response->status == 'canceled') {
                $order->logPaymentAttempt('Payment canceled successfully', 1, $data, $response);
            } else {
                $order->logPaymentAttempt('Payment canceled failed', 0, $data, $response);
            }

            return $response;
        } catch (Exception $ex) {
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
                if (in_array($paymentIntent->status, ['requires_capture', 'succeeded'])) {
                    return $paymentIntent;
                }

                $fields = $this->getPaymentFormFields($order, [], true);
                $gateway->paymentIntents->update($paymentIntent->id, array_except($fields, [
                    'capture_method', 'setup_future_usage',
                ]), $stripeOptions);

                return $paymentIntent;
            }
        } catch (Exception $ex) {
            return false;
        }
    }

    //
    // Payment Profiles
    //

    public function supportsPaymentProfiles(): bool
    {
        return true;
    }

    public function updatePaymentProfile(Customer $customer, array $data = []): PaymentProfile
    {
        return $this->handleUpdatePaymentProfile($customer, $data);
    }

    public function deletePaymentProfile(Customer $customer, PaymentProfile $profile)
    {
        $this->handleDeletePaymentProfile($customer, $profile);
    }

    public function payFromPaymentProfile(Order $order, array $data = [])
    {
        $host = $this->getHostObject();
        $profile = $host->findPaymentProfile($order->customer);

        if (!$profile || !$profile->hasProfileData()) {
            throw new ApplicationException('Payment profile not found');
        }

        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();

        try {
            $fields = $this->getPaymentFormFields($order);
            $fields['customer'] = array_get($profile->profile_data, 'customer_id');
            $fields['payment_method'] = array_get($profile->profile_data, 'card_id');
            $fields['off_session'] = true;
            $fields['confirm'] = true;

            $intent = $gateway->paymentIntents->create(
                array_except($fields, ['setup_future_usage']),
                $stripeOptions
            );

            if ($intent->status !== 'succeeded') {
                throw new Exception('Status '.$intent->status);
            }

            $order->logPaymentAttempt('Payment successful', 1, $fields, $intent, true);
            $order->updateOrderStatus($host->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();
        } catch (Exception $e) {
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

        if (!$profile) {
            $profile = $this->model->initPaymentProfile($customer);
        }

        $this->updatePaymentProfileData($profile, [
            'customer_id' => $customerId,
            'card_id' => $cardId,
        ], $cardData);

        return $profile;
    }

    protected function handleDeletePaymentProfile($customer, $profile)
    {
        if (!isset($profile->profile_data['customer_id'])) {
            return;
        }

        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();
        $gateway->customers->delete($profile->profile_data['customer_id'], [], $stripeOptions);

        $this->updatePaymentProfileData($profile);
    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $customerId = array_get($profileData, 'customer_id');

        $response = false;
        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();
        $newCustomerRequired = !$customerId;

        if (!$newCustomerRequired) {
            try {
                $response = $gateway->customers->retrieve($customerId, [], $stripeOptions);

                if (isset($response->deleted)) {
                    $newCustomerRequired = true;
                }
            } catch (Exception $e) {
                $newCustomerRequired = true;
            }
        }

        try {
            if ($newCustomerRequired) {
                $response = $gateway->customers->create([
                    'name' => $customer->full_name,
                    'email' => $customer->email,
                ], $stripeOptions);
            }
        } catch (Exception $ex) {
            throw new ApplicationException($ex->getMessage());
        }

        return $response;
    }

    protected function createOrFetchCard($customerId, $profileData, $data)
    {
        $cardId = array_get($profileData, 'card_id');
        $token = array_get($data, 'stripe_payment_method');

        $response = false;
        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();
        $newCardRequired = !$cardId;

        if (!$newCardRequired) {
            try {
                $response = $gateway->paymentMethods->retrieve($cardId, [], $stripeOptions);

                if ($response->customer != $customerId) {
                    $newCardRequired = true;
                }
            } catch (Exception $e) {
                $newCardRequired = true;
            }
        }

        if ($newCardRequired) {
            try {
                $response = $gateway->paymentMethods->attach($token, [
                    'customer' => $customerId,
                ], $stripeOptions);
            } catch (Exception $ex) {
                throw new ApplicationException($ex->getMessage());
            }
        }

        return $response;
    }

    /**
     * @param \Igniter\PayRegister\Models\PaymentProfile $profile
     * @param array $profileData
     * @param array $cardData
     * @return \Igniter\PayRegister\Models\PaymentProfile
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
        if (!is_null($paymentLog->refunded_at) || !is_array($paymentLog->response)) {
            throw new ApplicationException('Nothing to refund');
        }

        if (!array_get($paymentLog->response, 'status') === 'succeeded'
            || !array_get($paymentLog->response, 'object') === 'payment_intent'
        ) {
            throw new ApplicationException('No charge to refund');
        }

        $paymentChargeId = array_get($paymentLog->response, 'id');
        $fields = $this->getPaymentRefundFields($order, $data);

        if ($fields['amount'] > $order->order_total) {
            throw new ApplicationException('Refund amount should be be less than or equal to the order total');
        }

        try {
            $gateway = $this->createGateway();
            $response = $gateway->refunds->create(array_merge($fields, [
                'payment_intent' => $paymentChargeId,
            ]), $this->getStripeOptions());

            if ($response->status === 'failed') {
                throw new Exception('Refund failed');
            }

            $message = sprintf('Payment intent %s refunded successfully -> (%s: %s)',
                $paymentChargeId,
                currency_format($fields['amount']),
                array_get($response->toArray(), 'refunds.data.0.id')
            );

            $order->logPaymentAttempt($message, 1, $fields, $response->toArray());
            $paymentLog->markAsRefundProcessed();
        } catch (Exception $e) {
            $order->logPaymentAttempt('Refund failed -> '.$e->getMessage(), 0, $fields, []);
        }
    }

    protected function getPaymentFormFields($order, $data = [], $updatingIntent = false)
    {
        $fields = [
            'amount' => number_format($order->order_total, 2, '', ''),
            'currency' => currency()->getUserCurrency(),
            'capture_method' => $this->shouldAuthorizePayment() ? 'manual' : 'automatic',
            'metadata' => [
                'order_id' => $order->order_id,
            ],
        ];

        if ($this->supportsPaymentProfiles() && $order->customer) {
            $fields['setup_future_usage'] = 'off_session';
        }

        $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, $data, $updatingIntent]);

        return $fields;
    }

    protected function getPaymentCaptureFields($order, $fields = [])
    {
        $eventResult = $this->fireSystemEvent('payregister.stripe.extendCaptureFields', [$fields, $order], false);
        if (is_array($eventResult) && array_filter($eventResult)) {
            $fields = array_merge($fields, ...$eventResult);
        }

        return $fields;
    }

    protected function getPaymentRefundFields($order, $data)
    {
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        $fields = [
            'amount' => number_format($refundAmount, 2, '', ''),
        ];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendRefundFields', [$fields, $order, $data], false);
        if (is_array($eventResult) && array_filter($eventResult)) {
            $fields = array_merge($fields, ...$eventResult);
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

        $stripeClient = new StripeClient([
            'api_key' => $this->getSecretKey(),
        ]);

        $this->fireSystemEvent('payregister.stripe.extendGateway', [$stripeClient]);

        return $stripeClient;
    }

    //
    // Webhook
    //

    public function processWebhookUrl()
    {
        if (strtolower(request()->method()) !== 'post') {
            return response('Request method must be POST', 400);
        }

        $payload = $this->getWebhookPayload();

        if (!isset($payload['type']) || !strlen($eventType = $payload['type'])) {
            return response('Missing webhook event name', 400);
        }

        $eventName = Str::studly(str_replace('.', '_', $eventType));
        $methodName = 'webhookHandle'.$eventName;

        if (method_exists($this, $methodName)) {
            $this->$methodName($payload);
        }

        Event::fire('payregister.stripe.webhook.handle'.$eventName, [$payload]);

        return response('Webhook Handled');
    }

    protected function webhookHandlePaymentIntentSucceeded($payload)
    {
        if ($order = Order::find($payload['data']['object']['metadata']['order_id'])) {
            if (!$order->isPaymentProcessed()) {
                if ($payload['data']['object']['status'] === 'requires_capture') {
                    $order->logPaymentAttempt('Payment authorized via webhook', 1, [], $payload['data']['object']);
                } else {
                    $order->logPaymentAttempt('Payment successful via webhook', 1, [], $payload['data']['object'], true);
                }

                $order->updateOrderStatus($this->model->order_status, ['notify' => false]);
                $order->markAsPaymentProcessed();
            }
        }
    }

    protected function getWebhookPayload(): array
    {
        if (!$webhookSecret = $this->getWebhookSecret()) {
            return json_decode(request()->getContent(), true);
        }

        $event = \Stripe\Webhook::constructEvent(
            request()->getContent(),
            request()->header('HTTP_STRIPE_SIGNATURE'),
            $webhookSecret
        );

        return $event->toArray();
    }
}
