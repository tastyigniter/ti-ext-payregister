<?php

namespace Igniter\PayRegister\Payments;

use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Classes\PayPalClient;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Illuminate\Support\Facades\Redirect;

class PaypalExpress extends BasePaymentGateway
{
    use WithPaymentRefund;

    public static ?string $paymentFormView = 'igniter.payregister::_partials.paypalexpress.payment_form';

    public function defineFieldsConfig()
    {
        return 'igniter.payregister::/models/paypalexpress';
    }

    public function registerEntryPoints()
    {
        return [
            'paypal_return_url' => 'processReturnUrl',
            'paypal_cancel_url' => 'processCancelUrl',
        ];
    }

    public function isSandboxMode()
    {
        return $this->model->api_mode == 'sandbox';
    }

    public function getApiUsername()
    {
        return $this->isSandboxMode() ? $this->model->api_sandbox_user : $this->model->api_user;
    }

    public function getApiPassword()
    {
        return $this->isSandboxMode() ? $this->model->api_sandbox_pass : $this->model->api_pass;
    }

    /**
     * @param array $data
     * @param \Igniter\PayRegister\Models\Payment $host
     * @param \Igniter\Cart\Models\Order $order
     *
     * @return mixed
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validateApplicableFee($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        try {
            $response = $this->createClient()->createOrder($fields);

            $redirectUrl = collect($response->json('links', []))->where('rel', 'payer-action')->value('href');
            if ($response->ok() && $redirectUrl) {
                return Redirect::to($redirectUrl);
            }

            $order->logPaymentAttempt('Payment error -> '.$response->json('message'), 0, $fields, $response->json());
        } catch (Exception $ex) {
            logger()->error($ex);
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, $ex->getTrace());
        }

        throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
    }

    public function processReturnUrl($params)
    {
        $hash = $params[0] ?? null;
        $redirectPage = input('redirect');
        $cancelPage = input('cancel');

        $order = $this->createOrderModel()->whereHash($hash)->first();

        try {
            throw_unless($order, new ApplicationException('No order found'));
            throw_unless(strlen($redirectPage), new ApplicationException('No redirect page found'));
            throw_unless(strlen($cancelPage), new ApplicationException('No cancel page found'));

            throw_if(
                !($paymentMethod = $order->payment_method) || $paymentMethod->getGatewayClass() != static::class,
                new ApplicationException('No valid payment method found')
            );

            throw_unless(
                strlen($token = request()->input('token', '')),
                new ApplicationException('Missing valid token in response')
            );

            $response = $this->createClient()->getOrder($token);
            throw_if(
                array_get($response, 'status') !== 'APPROVED',
                new ApplicationException('Payment is not approved')
            );

            if (array_get($response, 'intent') === 'CAPTURE') {
                $response = $this->createClient()->captureOrder($token);

                if ($response->json('purchase_units.0.payments.captures.0.status') === 'COMPLETED') {
                    $order->logPaymentAttempt('Payment successful', 1, request()->input(), $response->json(), true);
                    $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
                    $order->markAsPaymentProcessed();
                }
            } elseif (array_get($response, 'intent') === 'AUTHORIZE') {
                $response = $this->createClient()->authorizeOrder($token);

                if ($response->json('purchase_units.0.payments.authorizations.0.status') === 'CREATED') {
                    $order->logPaymentAttempt('Payment authorized', 1, request()->input(), $response->json());
                    $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
                    $order->markAsPaymentProcessed();
                }
            }

            return Redirect::to(page_url($redirectPage, [
                'id' => $order->getKey(),
                'hash' => $order->hash,
            ]));
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, [], $ex->getTrace());
            flash()->warning($ex->getMessage())->important();
        }

        return Redirect::to(page_url($cancelPage));
    }

    public function processCancelUrl($params)
    {
        $hash = $params[0] ?? null;
        throw_unless(
            $order = $this->createOrderModel()->whereHash($hash)->first(),
            new ApplicationException('No order found')
        );

        throw_unless(
            strlen($redirectPage = request()->input('redirect')),
            new ApplicationException('No redirect page found')
        );

        throw_if(
            !($paymentMethod = $order->payment_method) || $paymentMethod->getGatewayClass() != static::class,
            new ApplicationException('No valid payment method found')
        );

        $order->logPaymentAttempt('Payment canceled by customer', 0, [], request()->input());

        return Redirect::to(page_url($redirectPage));
    }

    public function processRefundForm($data, $order, $paymentLog)
    {
        throw_if(
            !is_null($paymentLog->refunded_at) || !is_array($paymentLog->response),
            new ApplicationException('Nothing to refund')
        );

        throw_if(
            array_get($paymentLog->response, 'purchase_units.0.payments.captures.0.status') !== 'COMPLETED',
            new ApplicationException('No charge to refund')
        );

        $paymentId = array_get($paymentLog->response, 'purchase_units.0.payments.captures.0.id');
        $fields = $this->getPaymentRefundFields($order, $data);

        try {
            $response = $this->createClient()->refundPayment($paymentId, $fields);

            $message = sprintf('Payment %s refunded successfully -> (%s: %s)',
                $paymentId, array_get($data, 'refund_type'), $response->json('id')
            );

            $order->logPaymentAttempt($message, 1, $fields, $response->json());
            $paymentLog->markAsRefundProcessed();
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Refund failed -> '.$ex->getMessage(), 0, $fields, []);

            throw new Exception('Refund failed');
        }
    }

    protected function createClient(): PayPalClient
    {
        $client = new PayPalClient($this->getApiUsername(), $this->getApiPassword(), $this->isSandboxMode());

        $this->fireSystemEvent('payregister.paypalexpress.extendGateway', [$client]);

        return $client;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $currencyCode = currency()->getUserCurrency();

        $cancelUrl = $this->makeEntryPointUrl('paypal_cancel_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('paypal_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'intent' => $this->model->api_action === 'authorization' ? 'AUTHORIZE' : 'CAPTURE',
            'application_context' => [
                'brand_name' => setting('site_name'),
            ],
            'purchase_units' => [],
        ];

        $fields['payment_source']['paypal'] = [
            'email_address' => $order->email,
            'experience_context' => [
                'landing_page' => 'LOGIN',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl.'?redirect='.array_get($data, 'cancelPage'),
            ],
        ];

        $fields['purchase_units'][] = [
            'reference_id' => $order->hash,
            'custom_id' => $order->getKey(),
//            'items' => $order->getOrderMenus()->map(function(OrderMenu $orderMenu) use ($currencyCode) {
//                return [
//                    'name' => $orderMenu->name,
//                    'quantity' => $orderMenu->quantity,
//                    'unit_amount' => [
//                        'currency_code' => $currencyCode,
//                        'value' => number_format($orderMenu->price, 2, '.', ''),
//                    ],
//                    'category' => 'PHYSICAL_GOODS',
//                ];
//            })->all(),
            'amount' => [
                'currency_code' => $currencyCode,
                'value' => number_format($order->order_total, 2, '.', ''),
            ],
        ];

        $this->fireSystemEvent('payregister.paypalexpress.extendFields', [&$fields, $order, $data]);

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
            'note_to_payer' => array_get($data, 'refund_reason'),
            'invoice_id' => $order->getKey(),
            'amount' => [
                'currency_code' => currency()->getUserCurrency(),
                'value' => number_format($refundAmount, 2, '.', ''),
            ],
        ];

        $eventResult = $this->fireSystemEvent('payregister.paypalexpress.extendRefundFields', [$fields, $order, $data], false);
        if (is_array($eventResult) && array_filter($eventResult)) {
            $fields = array_merge($fields, ...$eventResult);
        }

        return $fields;
    }
}
