<?php

namespace Igniter\PayRegister\Payments;

use Exception;
use Igniter\Admin\Classes\BasePaymentGateway;
use Igniter\Admin\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Igniter\Flame\Traits\EventEmitter;
use Illuminate\Support\Facades\Redirect;
use Omnipay\Omnipay;

class PaypalExpress extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    public function registerEntryPoints()
    {
        return [
            'paypal_return_url' => 'processReturnUrl',
            'paypal_cancel_url' => 'processCancelUrl',
        ];
    }

    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
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

    public function getApiSignature()
    {
        return $this->isSandboxMode() ? $this->model->api_sandbox_signature : $this->model->api_signature;
    }

    /**
     * @param array $data
     * @param \Igniter\Admin\Models\Payment $host
     * @param \Igniter\Admin\Models\Order $order
     *
     * @return mixed
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            if ($response->isRedirect())
                return Redirect::to($response->getRedirectUrl());

            $order->logPaymentAttempt('Payment error -> '.$response->getMessage(), 0, $fields, $response->getData());
            throw new ApplicationException($response->getMessage());
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
            if (!$hash || !$order instanceof Order)
                throw new ApplicationException('No order found');

            if (!strlen($redirectPage))
                throw new ApplicationException('No redirect page found');

            if (!strlen($cancelPage))
                throw new ApplicationException('No cancel page found');

            $paymentMethod = $order->payment_method;
            if (!$paymentMethod || $paymentMethod->getGatewayClass() != static::class)
                throw new ApplicationException('No valid payment method found');

            $gateway = $this->createGateway();
            $fields = $this->getPaymentFormFields($order);
            $response = $gateway->completePurchase($fields)->send();

            if (!$response->isSuccessful())
                throw new ApplicationException($response->getMessage());

            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
            $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
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

    public function processCancelUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash || !$order instanceof Order)
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
        $gateway = Omnipay::create('PayPal_Express');

        $gateway->setUsername($this->getApiUsername());
        $gateway->setPassword($this->getApiPassword());
        $gateway->setSignature($this->getApiSignature());
        $gateway->setTestMode($this->isSandboxMode());
        $gateway->setBrandName(setting('site_name'));

        $this->fireSystemEvent('payregister.paypalexpress.extendGateway', [$gateway]);

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $cancelUrl = $this->makeEntryPointUrl('paypal_cancel_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('paypal_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
            'cancelUrl' => $cancelUrl.'?redirect='.array_get($data, 'cancelPage'),
            'returnUrl' => $returnUrl,
        ];

        $this->fireSystemEvent('payregister.paypalexpress.extendFields', [&$fields, $order, $data]);

        return $fields;
    }
}
