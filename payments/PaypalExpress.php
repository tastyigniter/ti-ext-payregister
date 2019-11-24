<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Illuminate\Support\Facades\Log;
use Omnipay\Omnipay;
use Redirect;

class PaypalExpress extends BasePaymentGateway
{
    public function registerEntryPoints()
    {
        return [
            'paypal_return_url' => 'processReturnUrl',
            'paypal_cancel_url' => 'processCancelUrl',
        ];
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
        $paymentMethod = $order->payment_method;
        if (!$paymentMethod OR $paymentMethod->code != $host->code)
            throw new ApplicationException('Payment method not found');

        if (!$this->isApplicable($order->order_total, $host))
            throw new ApplicationException(sprintf(
                lang('igniter.payregister::default.alert_min_order_total'),
                currency_format($host->order_total),
                $host->name
            ));

        try {
            $gateway = $this->createGateway($host);
            $fields = $this->getPaymentFormFields($order, $data);
            $response = $gateway->purchase($fields)->send();

            if ($response->isRedirect())
                return Redirect::to($response->getRedirectUrl());

            $order->logPaymentAttempt('Payment error -> '.$response->getMessage(), 1, $fields, $response->getData());
            throw new ApplicationException($response->getMessage());
        }
        catch (Exception $ex) {
            Log::error($ex->getMessage());
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function processReturnUrl($params)
    {
        $hash = $params[0] ?? null;
        $redirectPage = input('redirect');
        $cancelPage = input('cancel');

        try {
            $order = $this->createOrderModel()->whereHash($hash)->first();
            if (!$hash OR !$order)
                throw new ApplicationException('No order found');

            if (!strlen($redirectPage))
                throw new ApplicationException('No redirect page found');

            if (!strlen($cancelPage))
                throw new ApplicationException('No cancel page found');

            $paymentMethod = $order->payment_method;
            if (!$paymentMethod OR $paymentMethod->getGatewayClass() != static::class)
                throw new ApplicationException('No valid payment method found');

            $gateway = $this->createGateway($paymentMethod);
            $fields = $this->getPaymentFormFields($order);
            $response = $gateway->completePurchase($fields)->send();

            if (!$response->isSuccessful())
                throw new ApplicationException('Sorry, your payment was not successful. Please contact your bank or try again later.');

            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
            $order->updateOrderStatus($paymentMethod->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();

            return Redirect::to(page_url($redirectPage, [
                'id' => $order->getKey(),
                'hash' => $order->hash,
            ]));
        }
        catch (Exception $ex) {
            flash()->warning($ex->getMessage())->important();
        }

        return Redirect::to(page_url($cancelPage));
    }

    public function processCancelUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash OR !$order)
            throw new ApplicationException('No order found');

        if (!strlen($redirectPage = input('redirect')))
            throw new ApplicationException('No redirect page found');

        $paymentMethod = $order->payment_method;
        if (!$paymentMethod OR $paymentMethod->getGatewayClass() != static::class)
            throw new ApplicationException('No valid payment method found');

        $order->logPaymentAttempt('Payment canceled by customer', 0, input());

        return Redirect::to(page_url($redirectPage));
    }

    protected function createGateway($host)
    {
        $gateway = Omnipay::create('PayPal_Express');

        $gateway->setUsername($host->api_user);
        $gateway->setPassword($host->api_pass);
        $gateway->setSignature($host->api_signature);
        $gateway->setTestMode($host->api_mode == 'sandbox');
        $gateway->setBrandName(setting('site_name'));

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $cancelUrl = $this->makeEntryPointUrl('paypal_cancel_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('paypal_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
            'cancelUrl' => $cancelUrl.'?redirect='.array_get($data, 'cancelPage'),
            'returnUrl' => $returnUrl,
        ];
    }
}