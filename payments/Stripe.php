<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Igniter\Flame\Traits\EventEmitter;
use Illuminate\Support\Facades\Log;
use Omnipay\Omnipay;
use Redirect;
use Session;

class Stripe extends BasePaymentGateway
{
    use EventEmitter;

    public function registerEntryPoints()
    {
        return [
            'stripe_return_url' => 'processReturnUrl',
        ];
    }

    public function getHiddenFields()
    {
        return [
            'stripe_payment_method' => '',
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
        $controller->addCss('$/igniter/payregister/assets/stripe.css', 'stripe-css');
        $controller->addJs('https://js.stripe.com/v3/', 'stripe-js');
        $controller->addJs('$/igniter/payregister/assets/process.stripe.js', 'process-stripe-js');
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @return bool|\Illuminate\Http\RedirectResponse
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

            if ($response->isRedirect()) {
                Session::put('ti_payregister_stripe_intent', $response->getPaymentIntentReference());

                return Redirect::to($response->getRedirectUrl());
            }

            if (!$response->isSuccessful()) {
                $order->logPaymentAttempt('Payment error -> '.$response->getMessage(), 1, $fields, $response->getData());
                throw new Exception($response->getMessage());
            }

            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
            $order->updateOrderStatus($host->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();
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

            $gateway = $this->createGateway();
            $fields = $this->getPaymentFormFields($order);
            $fields['paymentIntentReference'] = Session::get('ti_payregister_stripe_intent');
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

    /**
     * @return \Omnipay\Common\GatewayInterface|\Omnipay\Stripe\Gateway
     */
    protected function createGateway()
    {
        $gateway = Omnipay::create('Stripe\PaymentIntents');

        $gateway->setApiKey($this->getSecretKey());

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $returnUrl = $this->makeEntryPointUrl('stripe_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'currency' => currency()->getUserCurrency(),
            'transactionId' => $order->order_id,
            'paymentMethod' => array_get($data, 'stripe_payment_method'),
            'returnUrl' => $returnUrl,
            'confirm' => TRUE,
        ];

        $this->fireSystemEvent('payregister.stripe.extendFields', [&$fields, $order, $data]);

        return $fields;
    }
}