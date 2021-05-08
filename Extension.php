<?php

namespace Igniter\PayRegister;

use Admin\Models\Payments_model;
use Admin\Widgets\Form;
use Event;
use System\Classes\BaseExtension;

class Extension extends BaseExtension
{
    public function registerPaymentGateways()
    {
        return [
            'Igniter\PayRegister\Payments\Cod' => [
                'code' => 'cod',
                'name' => 'lang:igniter.payregister::default.cod.text_payment_title',
                'description' => 'lang:igniter.payregister::default.cod.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\PaypalExpress' => [
                'code' => 'paypalexpress',
                'name' => 'lang:igniter.payregister::default.paypal.text_payment_title',
                'description' => 'lang:igniter.payregister::default.paypal.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\AuthorizeNetAim' => [
                'code' => 'authorizenetaim',
                'name' => 'lang:igniter.payregister::default.authorize_net_aim.text_payment_title',
                'description' => 'lang:igniter.payregister::default.authorize_net_aim.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\Stripe' => [
                'code' => 'stripe',
                'name' => 'lang:igniter.payregister::default.stripe.text_payment_title',
                'description' => 'lang:igniter.payregister::default.stripe.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\Mollie' => [
                'code' => 'mollie',
                'name' => 'lang:igniter.payregister::default.mollie.text_payment_title',
                'description' => 'lang:igniter.payregister::default.mollie.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\Square' => [
                'code' => 'square',
                'name' => 'lang:igniter.payregister::default.square.text_payment_title',
                'description' => 'lang:igniter.payregister::default.square.text_payment_desc',
            ],
        ];
    }

    public function registerFormWidgets()
    {
        return [
            'Igniter\PayRegister\FormWidgets\PaymentAttempts' => [
                'label' => 'Payment Attempts',
                'code' => 'paymentattempts',
            ],
        ];
    }

    public function boot()
    {
        Event::listen('admin.form.extendFieldsBefore', function (Form $form) {
            if ($form->model instanceof \Admin\Models\Orders_model) {
                $form->tabs['fields']['payment_logs']['type'] = 'paymentattempts';
                $form->tabs['fields']['payment_logs']['form'] = '$/igniter/payregister/models/config/payment_logs_model';
                $form->tabs['fields']['payment_logs']['columns']['is_refundable'] = [
                    'title' => 'Action',
                    'partial' => '$/igniter/payregister/views/partials/refund_button',
                ];
            }
        });

        Event::listen('main.theme.activated', function () {
            Payments_model::syncAll();
        });
    }
}
