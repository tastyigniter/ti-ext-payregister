<?php namespace SamPoyigi\Cod\Payments;

use Admin\Classes\BasePaymentGateway;

class Cod extends BasePaymentGateway
{
    public function onRender()
    {
        $this->lang->load('cod/cod');

        $data['code'] = $this->setting('code');
        $data['title'] = $this->setting('title', $data['code']);

        $order_data = $this->session->userdata('order_data');
        $data['payment'] = !empty($order_data['payment']) ? $order_data['payment'] : '';
        $data['minimum_order_total'] = $this->setting('order_total', 0);
        $data['order_total'] = $this->cart->total();

        $this->load->view('cod/cod', $data);
    }

    public function processPaymentForm($data, $host, $order)
    {
        $this->lang->load('cod/cod');

        $order_data = $this->session->userdata('order_data');                        // retrieve order details from session userdata
        $cart_contents = $this->session->userdata('cart_contents');                                                // retrieve cart contents

        if (empty($order_data) AND empty($cart_contents)) {
            return FALSE;
        }

        if (!empty($order_data['payment_settings']) AND !empty($order_data['payment']) AND $order_data['payment'] == 'cod') {                                            // else if payment method is cash on delivery

            $payment_settings = !empty($order_data['payment_settings']) ? $order_data['payment_settings'] : [];

            if (!empty($payment_settings['order_total']) AND $cart_contents['order_total'] < $payment_settings['order_total']) {
                $this->alert->set('danger', lang('alert_min_total'));

                return FALSE;
            }

            if (isset($payment_settings['order_status']) AND is_numeric($payment_settings['order_status'])) {
                $order_data['status_id'] = $payment_settings['order_status'];
            }

            $this->load->model('Orders_model');

            if ($this->Orders_model->completeOrder($order_data['order_id'], $order_data, $cart_contents)) {
                $this->redirect('checkout/success');                                    // $this->redirect to checkout success page with returned order id
            }
        }
    }
}