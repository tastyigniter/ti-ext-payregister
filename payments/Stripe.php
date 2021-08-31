<?php

namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
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

    public function getStripeJsOptions()
    {
        $options = [
            'locale' => app()->getLocale(),
        ];

        $this->fireSystemEvent('payregister.stripe.extendJsOptions', [&$options]);

        return $options;
    }

    public function createOrFetchIntent($order)
    {
        try {
            if ($order->isPaymentProcessed())
                return;

            $this->validatePaymentMethod($order, $this->model);

            $fields = $this->getPaymentFormFields($order);

            if (!$response = $this->updatePaymentIntentInSession($fields)) {
                $response = $this->createGateway()->paymentIntents->create($fields);

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
            $paymentIntent = $gateway->paymentIntents->retrieve($intentId);

            // At this stage the PaymentIntent status should either
            // be succeeded or requires_capture, we will only attach
            // the PaymentIntent to a customer since updating the amount
            // of a PaymentIntent with a succeeded status is not allowed
            if (!in_array($paymentIntent->status, ['requires_capture', 'succeeded']))
                return TRUE;

            if (array_get($data, 'create_payment_profile', 0) == 1 AND $order->customer) {
                $data['stripe_payment_method'] = $paymentIntent->payment_method;
                $profile = $this->updatePaymentProfile($order->customer, $data);
                $gateway->paymentIntents->update($paymentIntent->id, [
                    'customer' => array_get($profile->profile_data, 'customer_id'),
                ]);
            }

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

    protected function updatePaymentIntentInSession($fields)
    {
        try {
            if ($intentId = Session::get($this->sessionKey)) {
                $gateway = $this->createGateway();
                $paymentIntent = $gateway->paymentIntents->retrieve($intentId);

                // We can not update the amount of a PaymentIntent with one of the following statuses
                if (in_array($paymentIntent->status, ['requires_capture', 'succeeded']))
                    return $paymentIntent;

                $gateway->paymentIntents->update($paymentIntent->id, array_except($fields, ['capture_method']));

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

        if (!$profile OR !$profile->hasProfileData())
            throw new ApplicationException('Payment profile not found');

        $gateway = $this->createGateway();

        try {
            $fields = $this->getPaymentFormFields($order);
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

    protected function getPaymentFormFields($order, $onCreate = TRUE)
    {
        $fields = [
            'amount' => number_format($order->order_total, 2, '', ''),
            'currency' => currency()->getUserCurrency(),
            'capture_method' => $this->shouldAuthorizePayment() ? 'manual' : 'automatic',
        ];

        if ($this->supportsPaymentProfiles() AND $order->customer)
            $fields['setup_future_usage'] = 'off_session';

        if (!$onCreate) {
            array_forget($fields, ['capture_method', 'setup_future_usage']);
            $fields['receipt_email'] = $order->email;
            $fields['metadata'] = [
                'order_id' => $order->order_id,
                'customer_email' => $order->email,
            ];
        }

        $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, [], $onCreate]);

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
