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

    protected $intentSessionKey = 'ti_payregister_stripe_intent';

    public static ?string $paymentFormView = 'igniter.payregister::_partials.stripe.payment_form';

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
        $controller->addJs('https://js.stripe.com/v3/', 'stripe-js');
        $controller->addJs('igniter.payregister::/js/process.stripe.js', 'process-stripe-js');
    }

    public function completesPaymentOnClient()
    {
        return true;
    }

    public function shouldAuthorizePayment()
    {
        return $this->model->transaction_type === 'auth_only';
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
                $response = $this->createGateway()->paymentIntents->create(
                    array_only($fields, ['amount', 'currency', 'capture_method', 'setup_future_usage', 'customer']), $stripeOptions
                );

                Session::put($this->intentSessionKey, $response->id);
            }

            return $response?->client_secret;
        } catch (Exception $ex) {
            logger()->error($ex);
            $order->logPaymentAttempt('Creating checkout session failed: '.$ex->getMessage(), 0, [], []);
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
            if (!$intentId = Session::get($this->intentSessionKey)) {
                throw new Exception('Missing payment intent identifier in session.');
            }

            // Avoid logging payment and updating the order status more than once
            // For cases where the webhook is triggered before the user is redirected
            if ($order->isPaymentProcessed()) {
                return true;
            }

            $gateway = $this->createGateway();
            $stripeOptions = $this->getStripeOptions();
            $paymentIntent = $gateway->paymentIntents->retrieve($intentId, [
                'expand' => ['payment_method']
            ], $stripeOptions);

            // At this stage the PaymentIntent status should either
            // be succeeded or requires_capture, we will only update
            // the customer profile data
            if (!in_array($paymentIntent->status, ['requires_capture', 'succeeded'])) {
                return true;
            }

            if (array_get($data, 'create_payment_profile', 0) == 1 && $order->customer) {
                $this->updatePaymentProfile($order->customer, [
                    'card_id' => $paymentIntent->payment_method->id,
                    'card' => array_only($paymentIntent->payment_method->card->toArray(), [
                        'brand',
                        'country',
                        'display_brand',
                        'exp_month',
                        'exp_year',
                        'last4',
                        'three_d_secure_usage'
                    ]),
                ]);
            }

            if ($paymentIntent->status === 'requires_capture') {
                $order->logPaymentAttempt('Payment authorized', 1, $data, $paymentIntent->toArray());
            } else {
                $order->logPaymentAttempt('Payment successful', 1, $data, $paymentIntent->toArray(), true);
            }

            $order->updateOrderStatus($host->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();

            Session::forget($this->intentSessionKey);
        } catch (Exception $ex) {
            logger()->error($ex);
            $order->logPaymentAttempt('Payment error: '.$ex->getMessage(), 0, $data, $paymentIntent ?? []);

            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function captureAuthorizedPayment(Order $order, $data = [])
    {
        throw_unless(
            $paymentLog = $order->payment_logs()->firstWhere('is_success', true),
            new ApplicationException('No successful authorized payment to capture')
        );

        throw_unless(
            $paymentIntentId = array_get($paymentLog->response, 'id'),
            new ApplicationException('Missing payment intent ID in successful authorized payment response')
        );

        throw_if(
            $order->payment !== $this->model->code,
            new ApplicationException(sprintf('Invalid payment class for order: %s', $order->id))
        );

        try {
            $response = $this->createGateway()->paymentIntents->capture(
                $paymentIntentId,
                $this->getPaymentCaptureFields($order, $data),
                $this->getStripeOptions()
            );

            if ($response->status == 'succeeded') {
                $order->logPaymentAttempt('Payment successful', 1, $data, $response);
            } else {
                $order->logPaymentAttempt('Payment failed', 0, $data, $response);
            }

            return $response;
        } catch (Exception $ex) {
            logger()->error($ex);
            $order->logPaymentAttempt('Payment capture failed: '.$ex->getMessage(), 0, $data);
        }
    }

    public function cancelAuthorizedPayment(Order $order, $data = [])
    {
        throw_unless(
            $paymentLog = $order->payment_logs()->firstWhere('is_success', true),
            new ApplicationException('No successful authorized payment to cancel')
        );

        throw_unless(
            $paymentIntentId = array_get($paymentLog->response, 'id'),
            new ApplicationException('Missing payment intent ID in successful authorized payment response')
        );

        throw_if(
            $order->payment !== $this->model->code,
            new ApplicationException(sprintf('Invalid payment class for order: %s', $order->id))
        );

        try {
            $eventResult = $this->fireSystemEvent('payregister.stripe.extendCancelFields', [$data, $order], false);
            if (is_array($eventResult) && array_filter($eventResult)) {
                $data = array_merge($data, ...$eventResult);
            }

            $response = $this->createGateway()->paymentIntents->cancel(
                $paymentIntentId, $data, $this->getStripeOptions()
            );

            if ($response->status == 'canceled') {
                $order->logPaymentAttempt('Payment canceled successfully', 1, $data, $response);
            } else {
                $order->logPaymentAttempt('Canceling payment failed', 0, $data, $response);
            }

            return $response;
        } catch (Exception $ex) {
            logger()->error($ex);
            $order->logPaymentAttempt('Payment canceled failed: '.$ex->getMessage(), 0, $data);
        }
    }

    public function updatePaymentIntentSession($order)
    {
        try {
            if ($intentId = Session::get($this->intentSessionKey)) {
                $gateway = $this->createGateway();
                $stripeOptions = $this->getStripeOptions();
                $paymentIntent = $gateway->paymentIntents->retrieve($intentId, [], $stripeOptions);

                // We can not update the amount of a PaymentIntent with one of the following statuses
                if (in_array($paymentIntent->status, ['requires_capture', 'succeeded'])) {
                    return $paymentIntent;
                }

                $fields = $this->getPaymentFormFields($order, [], true);
                return $gateway->paymentIntents->update($paymentIntent->id, array_except($fields, [
                    'capture_method', 'setup_future_usage', 'customer'
                ]), $stripeOptions);
            }
        } catch (Exception $ex) {
            logger()->error($ex);
            $order->logPaymentAttempt('Updating checkout session failed: '.$ex->getMessage(), 1, [], array_slice($ex->getTrace(), 20));
            flash()->warning($ex->getMessage())->important()->now();

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
        if (!$profile = $this->model->findPaymentProfile($customer)) {
            $profile = $this->model->initPaymentProfile($customer);
        }

        $profileData = array_merge((array)$profile->profile_data, $data);

        if (!$profile) {
            $profile = $this->model->initPaymentProfile($customer);
        }

        $profile->card_brand = strtolower(array_get($data, 'card.brand'));
        $profile->card_last4 = array_get($data, 'card.last4');
        $profile->setProfileData($profileData);

        return $profile;
    }

    public function deletePaymentProfile(Customer $customer, PaymentProfile $profile)
    {
        if (!isset($profile->profile_data['customer_id'])) {
            return;
        }

        $this->createGateway()->customers->delete($profile->profile_data['customer_id'], [], $this->getStripeOptions());
    }

    public function payFromPaymentProfile(Order $order, array $data = [])
    {
        $host = $this->getHostObject();
        if (!$order->customer || !$this->paymentProfileExists($order->customer)) {
            throw new ApplicationException('Payment profile not found or customer not logged in');
        }

        $gateway = $this->createGateway();
        $stripeOptions = $this->getStripeOptions();

        try {
            $fields = $this->getPaymentFormFields($order);
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
            logger()->error($e);
            $order->logPaymentAttempt('Payment error: '.$e->getMessage(), 0, $data, $intent ?? []);

            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function paymentProfileExists(Customer $customer): ?bool
    {
        $profile = $this->model->findPaymentProfile($customer);

        return $profile && array_get((array)$profile->profile_data, 'card_id');
    }

    protected function createOrFetchProfileData($customer): array
    {
        if (!$profile = $this->model->findPaymentProfile($customer)) {
            $profile = $this->model->initPaymentProfile($customer);
        }

        $profileData = (array)$profile->profile_data;
        $customerId = array_get($profileData, 'customer_id');

        $newCustomerRequired = !$customerId;
        if (!$newCustomerRequired) {
            try {
                $response = $this->createGateway()->customers->retrieve($customerId, [], $this->getStripeOptions());

                if (isset($response->deleted)) {
                    $newCustomerRequired = true;
                }
            } catch (Exception $e) {
                $newCustomerRequired = true;
            }
        }

        try {
            if ($newCustomerRequired) {
                $response = $this->createGateway()->customers->create([
                    'name' => $customer->full_name,
                    'email' => $customer->email,
                ], $this->getStripeOptions());

                $profileData['customer_id'] = $response->id;
                $profile->setProfileData($profileData);
            }
        } catch (Exception $ex) {
            throw new ApplicationException($ex->getMessage());
        }

        return $profileData;
    }

    //
    //
    //

    public function processRefundForm($data, $order, $paymentLog)
    {
        if (!is_null($paymentLog->refunded_at) || !is_array($paymentLog->response)) {
            throw new ApplicationException('Nothing to refund');
        }

        if (array_get($paymentLog->response, 'status') !== 'succeeded'
            || array_get($paymentLog->response, 'object') !== 'payment_intent'
        ) {
            throw new ApplicationException('No charge to refund');
        }

        $paymentChargeId = array_get($paymentLog->response, 'id');
        $fields = $this->getPaymentRefundFields($order, $data);

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
                array_get($data, 'refund_type'),
                array_get($response->toArray(), 'id')
            );

            $order->logPaymentAttempt($message, 1, $fields, $response->toArray());
            $paymentLog->markAsRefundProcessed();
        } catch (Exception $e) {
            logger()->error($e);
            $order->logPaymentAttempt('Refund failed: '.$e->getMessage(), 0, $fields, []);
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

            if ($profileData = $this->createOrFetchProfileData($order->customer)) {
                $fields['customer'] = array_get($profileData, 'customer_id');
                if ($paymentId = array_get($profileData, 'card_id')) {
                    $fields['payment_method'] = $paymentId;
                }
            }
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
        $refundAmount = array_get($data, 'refund_type') !== 'full'
            ? array_get($data, 'refund_amount') : $order->order_total;

        throw_if($refundAmount > $order->order_total, new ApplicationException(
            'Refund amount should be be less than or equal to the order total'
        ));

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
            request()->header('stripe-signature'),
            $webhookSecret
        );

        return $event->toArray();
    }
}
