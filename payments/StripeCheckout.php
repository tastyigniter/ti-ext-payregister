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
use Illuminate\Support\Str;
use Stripe\StripeClient;

class StripeCheckout extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    public function registerEntryPoints()
    {
        return [
            'stripe_checkout_webhook' => 'processWebhookUrl',
            'stripe_checkout_return_url' => 'processSuccessUrl',
            'stripe_checkout_cancel_url' => 'processCancelUrl',
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
    
    public function capturePaymentIntent($paymentIntentId, $order, $data = [])
    {
        if ($order->payment !== $this->model->code)
            return;

        try {
            $response = $this->createGateway()->paymentIntents->capture(
                $paymentIntentId,
                $data,
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
                $paymentIntentId, $data
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

    /**
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @return mixed
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        try {
            $gateway = $this->createGateway();
            $response = $gateway->checkout->sessions->create($fields);

            return Redirect::to($response->url);
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function processSuccessUrl($params)
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

            // Don't have detailed payment info, not refundable.
            $order->logPaymentAttempt('Payment successful (not final)', 1, [], $paymentMethod, true);
            $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();

            return Redirect::to(page_url($redirectPage, [
                'id' => $order->getKey(),
                'hash' => $order->hash,
            ]));
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, [], []);
            flash()->warning($ex->getMessage())->important();
        }

        return Redirect::to(page_url($cancelPage));
    }

    public function processCancelUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash || !$order instanceof Orders_model)
            throw new ApplicationException('No order found');

        if (!strlen($redirectPage = input('redirect')))
            throw new ApplicationException('No redirect page found');

        $paymentMethod = $order->payment_method;
        if (!$paymentMethod || $paymentMethod->getGatewayClass() != static::class)
            throw new ApplicationException('No valid payment method found');

        $order->logPaymentAttempt('Payment canceled by customer', 0, input());

        return Redirect::to(page_url($redirectPage));
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

    protected function getPaymentRefundFields($order, $data)
    {
        $fields = [];

        $eventResult = $this->fireSystemEvent('payregister.stripe.extendRefundFields', [$fields, $order, $data], false);
        if (is_array($eventResult))
            $fields = array_merge($fields, ...$eventResult);

        return $fields;
    }

    public function processRefundForm($data, $order, $paymentLog)
    {
        if (!is_null($paymentLog->refunded_at) || !is_array($paymentLog->response))
            throw new ApplicationException('Nothing to refund');

        if (!array_get($paymentLog->response, 'status') === 'succeeded'
            || !array_get($paymentLog->response, 'object') === 'payment_intent'
        ) throw new ApplicationException('No charge to refund');

        $paymentIntentId = array_get($paymentLog->response, 'payment_intent');
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        if ($refundAmount > $order->order_total)
            throw new ApplicationException('Refund amount should be be less than total');

        try {
            $gateway = $this->createGateway();
            $fields = $this->getPaymentRefundFields($order, $data);
            $response = $gateway->refunds->create(array_merge($fields, [
                'payment_intent' => $paymentIntentId,
                'amount' => number_format($refundAmount, 2, '', ''),
            ]));

            if ($response->status === 'failed')
                throw new Exception('Refund failed');

            $message = sprintf('Payment intent %s refunded successfully -> (%s: %s)',
                $paymentIntentId,
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

    protected function getPaymentFormFields($order, $data = [])
    {
        $cancelUrl = $this->makeEntryPointUrl('stripe_checkout_cancel_url').'/'.$order->hash;
        $successUrl = $this->makeEntryPointUrl('stripe_checkout_return_url').'/'.$order->hash;
        $successUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => currency()->getUserCurrency(),
                        // All amounts sent to Stripe must be in integers, representing the lowest currency unit (cents)
                        'unit_amount_decimal' => number_format($order->order_total, 2, '.', '') * 100,
                        'product_data' => [
                            'name' => 'Meals',
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'cancel_url' => $cancelUrl.'?redirect='.array_get($data, 'cancelPage'),
            'success_url' => $successUrl,
            'mode' => 'payment',
            'metadata' => [
                'order_id' => $order->order_id,
            ],
            'payment_intent_data' => [
                'capture_method' => $this->shouldAuthorizePayment() ? 'manual' : 'automatic',
            ],
        ];

        // Share the email field in our form to Stripe checkout session,
        // so customers don't need to enter twice
        if (!is_null(array_get($data, 'email'))) {
            // if is unregistered customer
            $fields['customer_email'] = array_get($data, 'email');
        } elseif (!is_null($order->customer) && !is_null($order->customer->email)) {
            // else if is registered, get email from customer profile
            $fields['customer_email'] = $order->customer->email;
        }

        $this->fireSystemEvent('payregister.stripecheckout.extendFields', [&$fields, $order, $data]);

        return $fields;
    }

    public function processWebhookUrl()
    {
        if (strtolower(request()->method()) !== 'post')
            return response('Request method must be POST', 400);

        $payload = json_decode(request()->getContent(), true);
        if (!isset($payload['type']) || !strlen($eventType = $payload['type']))
            return response('Missing webhook event name', 400);

        $eventName = Str::studly(str_replace('.', '_', $eventType));
        $methodName = 'webhookHandle'.$eventName;

        if (method_exists($this, $methodName))
            $this->$methodName($payload);

        Event::fire('payregister.stripecheckout.webhook.handle'.$eventName, [$payload]);

        return response('Webhook Handled');
    }

    protected function webhookHandleCheckoutSessionCompleted($payload)
    {
        if ($order = Orders_model::find($payload['data']['object']['metadata']['order_id'])) {
            if ($payload['data']['object']['status'] === 'requires_capture') {
                $order->logPaymentAttempt('Payment authorized', 1, [], $payload['data']['object']);
            } else {
                // Have detailed payment info, refundable.
                $order->logPaymentAttempt('Payment confirmed (Finalized, Refundable)', 1, [], $payload['data']['object'], true);
            }

            $order->updateOrderStatus($this->model->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();
        }
    }
}
