<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
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

    public function beforeRenderPaymentForm($host, $controller)
    {
        $endpoint = $this->getEndPoint();
        $controller->addJs($endpoint.'/v1/Accept.js', 'authorize-accept-js');
        $controller->addJs($endpoint.'/v3/AcceptUI.js', 'authorize-accept-ui-js');
        $controller->addJs('~/extensions/igniter/payregister/assets/authorizenetaim.js', 'authorizenetaim-js');
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

    public function onRender()
    {
        $this->lang->load('authorize_net_aim/authorize_net_aim');

        $data['code'] = 'authorize_net_aim';
        $data['title'] = $this->setting('title', $data['code']);

        $order_data = $this->session->userdata('order_data');                           // retrieve order details from session userdata
        $data['payment'] = $this->setting('payment', '');
        $data['minimum_order_total'] = is_numeric($this->setting('order_total')) ? $this->setting('order_total') : 0;
        $data['order_total'] = $this->cart->total();

        if (isset($this->input->post['authorize_cc_number'])) {
            $padsize = (strlen($this->input->post['authorize_cc_number']) < 7 ? 0 : strlen($this->input->post['authorize_cc_number']) - 7);
            $data['authorize_cc_number'] = substr($this->input->post['authorize_cc_number'], 0, 4).str_repeat('X', $padsize).substr($this->input->post['authorize_cc_number'], -3);
        }
        else {
            $data['authorize_cc_number'] = '';
        }

        if (isset($this->input->post['authorize_cc_exp_month'])) {
            $data['authorize_cc_exp_month'] = $this->input->post('authorize_cc_exp_month');
        }
        else {
            $data['authorize_cc_exp_month'] = '';
        }

        if (isset($this->input->post['authorize_cc_exp_year'])) {
            $data['authorize_cc_exp_year'] = $this->input->post('authorize_cc_exp_year');
        }
        else {
            $data['authorize_cc_exp_year'] = '';
        }

        if (isset($this->input->post['authorize_cc_cvc'])) {
            $data['authorize_cc_cvc'] = $this->input->post('authorize_cc_cvc');
        }
        else {
            $data['authorize_cc_cvc'] = '';
        }

        if (isset($this->input->post['authorize_country_id'])) {
            $data['authorize_country_id'] = $this->input->post('authorize_country_id');
        }
        else {
            $data['authorize_country_id'] = $this->config->item('country_id');
        }

        $data['order_type'] = $this->location->orderType();

        if ($this->input->post('authorize_address_id')) {
            $data['authorize_address_id'] = $this->input->post('authorize_address_id');                // retrieve existing_address value from $_POST data if set
        }
        else if ($this->customer->getAddressId()) {
            $data['authorize_address_id'] = $this->customer->getAddressId();                                        // retrieve customer default address id from customer library
        }
        else {
            $data['authorize_address_id'] = '';
        }

        if ($this->customer->islogged()) {
            $addresses = $this->Addresses_model->getAddresses($this->customer->getId());                            // retrieve customer addresses array from getAddresses method in Customers model
        }
        else {
            $addresses = [['address_id' => '0', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country_id' => $country_id]];
        }

        $data['addresses'] = [];
        foreach ($addresses as $address) {                                                    // loop through customer addresses arrary
            if (empty($address['country'])) {
                $country = $this->Countries_model->getCountry($address['country_id']);
                $address['country'] = !empty($address['country']) ? $address['country'] : $country['country_name'];
            }

            $data['addresses'][] = [                                                    // create array of address data to pass to view
                'address_id' => $address['address_id'],
                'address_1' => $address['address_1'],
                'address_2' => $address['address_2'],
                'city' => $address['city'],
                'state' => $address['state'],
                'postcode' => $address['postcode'],
                'country_id' => $address['country_id'],
                'address' => str_replace('<br />', ', ', $this->country->addressFormat($address)),
            ];
        }

        $data['countries'] = [];
        $results = $this->Countries_model->isEnabled()->get();                                        // retrieve countries array from getCountries method in locations model
        foreach ($results as $result) {                                                            // loop through crountries array
            $data['countries'][] = [                                                        // create array of countries data to pass to view
                'country_id' => $result['country_id'],
                'name' => $result['country_name'],
            ];
        }

        // pass array $data and load view files
        $this->load->view('authorize_net_aim/authorize_net_aim', $data);
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
        $cancelUrl = $this->makeEntryPointUrl('paypal_cancel_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('paypal_return_url').'/'.$order->hash;

        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'opaqueDataDescriptor' => array_get($data, 'authorizenetaim_DataDescriptor'),
            'opaqueDataValue' => array_get($data, 'authorizenetaim_DataValue'),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
            'cancelUrl' => $cancelUrl.'?redirect='.array_get($data, 'cancelPage'),
            'returnUrl' => $returnUrl.'?redirect='.array_get($data, 'successPage'),
        ];
    }
}