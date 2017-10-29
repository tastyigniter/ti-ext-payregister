<?php

class Stripe_model extends TI_Model
{
    public function __construct()
    {
        parent::__construct();

        $this->load->library('cart');
        $this->load->library('currency');
    }

    public function createCharge($token, $order_data = [])
    {
        if (empty($token) OR empty($order_data['order_id'])) {
            return FALSE;
        }

        $currency = $this->currency->getCurrencyCode();
        $zero_decimal_currencies = ['BIF', 'DJF', 'JPY', 'KRW', 'PYG', 'VND', 'XAF', 'XPF', 'CLP', 'GNF', 'KMF', 'MGA', 'RWF', 'VUV', 'XOF'];
        $order_total = round((float)$this->cart->order_total(), 2);

        $data = [];
        $data['currency'] = $currency;
        $data['amount'] = (int)(in_array($currency, $zero_decimal_currencies)) ? $order_total : $order_total * 100;
        $data['description'] = sprintf(lang('text_stripe_charge_description'), $this->config->item('site_name'), $order_data['email']);
        $data['source'] = $token;
        $data['receipt_email'] = $order_data['email'];
        $data['metadata'] = ['email' => $order_data['email'], 'order_id' => $order_data['order_id']];

        return $this->sendToStripe('charges', $data, $order_data);
    }

    protected function sendToStripe($end_point, $data = [], $order_data = [])
    {
        $settings = $this->Extensions_model->getSettings('stripe');

        $url = 'https://api.stripe.com/v1/'.$end_point;

        if (isset($settings['live_secret_key']) AND $settings['transaction_mode'] === 'live') {
            $options['HTTPHEADER'] = ["Authorization: Bearer ".$settings['live_secret_key']];
        }
        else if (isset($settings['live_secret_key'])) {
            $options['HTTPHEADER'] = ["Authorization: Bearer ".$settings['test_secret_key']];
        }

        $options['POSTFIELDS'] = $data;

        // Get response from the server.
        $response = get_remote_data($url, $options);
        $response = json_decode($response);

        if (isset($response->error->type) AND $response->error->type !== 'card_error') {
            log_message('error', "Stripe Error ->  {$order_data['order_id']}: {$response->error->message}");
        }

        return $response;
    }
}
