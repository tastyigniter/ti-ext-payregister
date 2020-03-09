<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Illuminate\Support\Facades\Log;
use Omnipay\Omnipay;

class AuthorizeNetAim extends BasePaymentGateway
{
    public function getHiddenFields()
    {
        return [
            'authorizenetaim_DataValue' => '',
            'authorizenetaim_DataDescriptor' => '',
        ];
    }

    public function getClientKey()
    {
        return $this->model->client_key;
    }

    public function getTransactionKey()
    {
        return $this->model->transaction_key;
    }

    public function getApiLoginID()
    {
        return $this->model->api_login_id;
    }

    public function isTestMode()
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getEndPoint()
    {
        return $this->isTestMode() ? 'https://jstest.authorize.net' : 'https://js.authorize.net';
    }

    public function getAcceptedCards()
    {
        return [
            'visa' => 'lang:igniter.payregister::default.authorize_net_aim.text_visa',
            'mastercard' => 'lang:igniter.payregister::default.authorize_net_aim.text_mastercard',
            'american_express' => 'lang:igniter.payregister::default.authorize_net_aim.text_american_express',
            'jcb' => 'lang:igniter.payregister::default.authorize_net_aim.text_jcb',
            'diners_club' => 'lang:igniter.payregister::default.authorize_net_aim.text_diners_club',
        ];
    }

    public function beforeRenderPaymentForm($host, $controller)
    {
        $endpoint = $this->getEndPoint();
        $controller->addJs($endpoint.'/v1/Accept.js', 'authorize-accept-js');
        $controller->addJs($endpoint.'/v3/AcceptUI.js', 'authorize-accept-ui-js');
        $controller->addJs('$/igniter/payregister/assets/authorizenetaim.js', 'authorizenetaim-js');
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

    protected function createGateway($host)
    {
        $gateway = Omnipay::create('AuthorizeNet_AIM');

        $gateway->setApiLoginId($host->api_login_id);
        $gateway->setTransactionKey($host->transaction_key);
        $gateway->setHashSecret($host->hash_secret);
        $gateway->setTestMode($host->transaction_mode != 'live');
        $gateway->setDeveloperMode($host->transaction_mode != 'live');

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'opaqueDataDescriptor' => array_get($data, 'authorizenetaim_DataDescriptor'),
            'opaqueDataValue' => array_get($data, 'authorizenetaim_DataValue'),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
        ];
    }
}