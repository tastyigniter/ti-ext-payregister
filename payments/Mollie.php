<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Illuminate\Support\Facades\Log;
use Omnipay\Omnipay;
use Redirect;
use Response;

class Mollie extends BasePaymentGateway
{
    public function registerEntryPoints()
    {
        return [
            'mollie_return_url' => 'processReturnUrl',
            'mollie_notify_url' => 'processNotifyUrl',
        ];
    }

    public function isTestMode()
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getApiKey()
    {
        return $this->isTestMode() ? $this->model->test_api_key : $this->model->live_api_key;
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

            if ($response->isRedirect())
                return Redirect::to($response->getRedirectUrl());

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

            if (!$order->isPaymentProcessed())
                throw new ApplicationException('Sorry, your payment was not successful. Please contact your bank or try again later.');

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

    public function processNotifyUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();
        if (!$hash OR !$order)
            return Response::json(['error' => 'No order found']);

        $paymentMethod = $order->payment_method;
        if (!$paymentMethod OR $paymentMethod->getGatewayClass() != static::class)
            return Response::json(['error' => 'No valid payment method found']);

        $gateway = $this->createGateway();
        $fields = $this->getPaymentFormFields($order);
        $response = $gateway->completePurchase($fields)->send();

        if ($response->isPaid()) {
            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getData());
            $order->updateOrderStatus($paymentMethod->order_status, ['notify' => FALSE]);
            $order->markAsPaymentProcessed();
        }
        else {
            $order->logPaymentAttempt('Payment unsuccessful', 1, $fields, $response->getData());
            $order->updateOrderStatus(setting('canceled_order_status'), ['notify' => FALSE]);
        }

        return Response::json(['success' => TRUE]);
    }

    /**
     * @return \Omnipay\Common\GatewayInterface|\Omnipay\Mollie\Gateway
     */
    protected function createGateway()
    {
        $gateway = Omnipay::create('Mollie');

        $gateway->setApiKey($this->getApiKey());

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $notifyUrl = $this->makeEntryPointUrl('mollie_notify_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('mollie_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'currency' => currency()->getUserCurrency(),
            'description' => 'Payment for Order '.$order->order_id,
            'metadata' => [
                'order_id' => $order->order_id,
            ],
            'returnUrl' => $returnUrl,
            'notifyUrl' => $notifyUrl,
        ];
    }
}