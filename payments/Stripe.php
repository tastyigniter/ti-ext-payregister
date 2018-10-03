<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Omnipay\Omnipay;

class Stripe extends BasePaymentGateway
{
    public function getHiddenFields()
    {
        return [
            'stripe_token' => '',
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

    public function beforeRenderPaymentForm($host, $controller)
    {
        $controller->addCss('~/extensions/igniter/payregister/assets/stripe.css', 'stripe-css');
        $controller->addJs('https://js.stripe.com/v3/', 'stripe-js');
        $controller->addJs('~/extensions/igniter/payregister/assets/process.stripe.js', 'process-stripe-js');
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @throws \ApplicationException
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
            $gateway = $this->createGateway();
            $fields = $this->getPaymentFormFields($order, $data);
            $response = $gateway->purchase($fields)->send();

            if (!$response->isSuccessful()) {
                $order->logPaymentAttempt('Payment error -> '.$response->getMessage(), 1, $fields, $response->getData());

                return FALSE;
            }

            if ($order->markAsPaymentProcessed()) {
                $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
                $order->updateOrderStatus($paymentMethod->order_status, ['notify' => FALSE]);
            }
        }
        catch (Exception $ex) {
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    protected function createGateway()
    {
        $gateway = Omnipay::create('Stripe');

        $gateway->setApiKey($this->getSecretKey());

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {

        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
            'token' => array_get($data, 'stripe_token'),
        ];
    }
}