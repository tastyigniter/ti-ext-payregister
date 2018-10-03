<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Exception;
use October\Rain\Exception\ApplicationException;
use Omnipay\Omnipay;
use Redirect;

class PaypalExpress extends BasePaymentGateway
{
    protected $orderModel = 'Igniter\Cart\Models\Orders_model';

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

            return FALSE;
        }
        catch (Exception $ex) {
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    public function processReturnUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash OR !$order)
            throw new ApplicationException('No order found');

        if (!strlen($redirectPage = input('redirect')))
            throw new ApplicationException('No redirect page found');

        if (!$paymentMethod = $order->payment_method OR $paymentMethod->getGatewayClass() != static::class)
            throw new ApplicationException('No valid payment method found');

        $gateway = $this->createGateway($paymentMethod);
        $fields = $this->getPaymentFormFields($order);
        $response = $gateway->completePurchase($fields)->send();

        if ($response->isSuccessful()) {
            if ($order->markAsPaymentProcessed()) {
                $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
                $order->updateOrderStatus($paymentMethod->order_status, ['notify' => FALSE]);
            }

            return Redirect::to($order->getUrl($redirectPage));
        }
    }

    public function processCancelUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash OR !$order)
            throw new ApplicationException('No order found');

        if (!strlen($redirectPage = input('redirect')))
            throw new ApplicationException('No redirect page found');

        if (!$paymentMethod = $order->payment_method OR $paymentMethod->getGatewayClass() != static::class)
            throw new ApplicationException('No valid payment method found');

        $order->logPaymentAttempt('Payment canceled by customer', 0, input());

        return Redirect::to($order->getUrl($redirectPage, null));
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

        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
            'cancelUrl' => $cancelUrl.'?redirect='.array_get($data, 'cancelPage'),
            'returnUrl' => $returnUrl.'?redirect='.array_get($data, 'successPage'),
        ];
    }
}