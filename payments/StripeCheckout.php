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
            'stripe_webhook' => 'processWebhookUrl',
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

            $order->logPaymentAttempt('Payment successful', 1, [], $paymentMethod, true);
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
                            'name' => 'Test',
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
        ];

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
            if (!$order->isPaymentProcessed()) {
                if ($payload['data']['object']['status'] === 'requires_capture') {
                    $order->logPaymentAttempt('Payment authorized', 1, [], $payload['data']['object']);
                } else {
                    $order->logPaymentAttempt('Payment successful', 1, [], $payload['data']['object'], true);
                }

                $order->updateOrderStatus($this->model->order_status, ['notify' => false]);
                $order->markAsPaymentProcessed();
            }
        }
    }
}
