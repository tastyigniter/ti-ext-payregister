<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Omnipay\Omnipay;

class AuthorizeNetAim extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

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

    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * @param self $host
     * @param \Main\Classes\MainController $controller
     */
    public function beforeRenderPaymentForm($host, $controller)
    {
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
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['opaqueDataDescriptor'] = array_get($data, 'authorizenetaim_DataDescriptor');
        $fields['opaqueDataValue'] = array_get($data, 'authorizenetaim_DataValue');

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            $this->handlePaymentResponse($response, $order, $host, $fields);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    //
    //
    //

    protected function createGateway()
    {
        $gateway = Omnipay::create('AuthorizeNet_AIM');

        $gateway->setApiLoginId($this->getApiLoginID());
        $gateway->setTransactionKey($this->getTransactionKey());
        $gateway->setTestMode($this->isTestMode());
        $gateway->setDeveloperMode($this->isTestMode());

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $fields = [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
        ];

        $this->fireSystemEvent('payregister.authorizenetaim.extendFields', [&$fields, $order, $data]);

        return $fields;
    }
}