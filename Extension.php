<?php namespace Igniter\PayRegister;

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
        ];
    }

    public function registerPermissions()
    {
        return [
            'Payment.Cod' => [
                'description' => 'Ability to manage cash on delivery payment',
                'group' => 'payment',
            ],
            'Payment.PaypalExpress' => [
                'group' => 'payment',
                'description' => 'Ability to manage paypal express payment',
            ],
            'Payment.AuthorizeNetAIM' => [
                'group' => 'payment',
                'description' => 'Ability to manage Authorize.Net payment extension',
            ],
            'Payment.Stripe' => [
                'group' => 'payment',
                'description' => 'Ability to manage Stripe payment extension',
            ],
        ];
    }
}
