<?php namespace SamPoyigi\PayRegister;

use System\Classes\BaseExtension;

class Extension extends BaseExtension
{
    public function registerPaymentGateways()
    {
        return [
            'SamPoyigi\PayRegister\Payments\Cod'             => [
                'code'        => 'cod',
                'name'        => 'lang:sampoyigi.payregister::default.cod.text_payment_title',
                'description' => 'lang:sampoyigi.payregister::default.cod.text_payment_desc',
            ],
            'SamPoyigi\PayRegister\Payments\PaypalExpress'   => [
                'code'        => 'paypalexpress',
                'name'        => 'lang:sampoyigi.payregister::default.paypal.text_payment_title',
                'description' => 'lang:sampoyigi.payregister::default.paypal.text_payment_desc',
            ],
            'SamPoyigi\PayRegister\Payments\AuthorizeNetAim' => [
                'code'        => 'authorizenetaim',
                'name'        => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_payment_title',
                'description' => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_payment_desc',
            ],
            'SamPoyigi\PayRegister\Payments\Stripe'          => [
                'code'        => 'stripe',
                'name'        => 'lang:sampoyigi.payregister::default.stripe.text_payment_title',
                'description' => 'lang:sampoyigi.payregister::default.stripe.text_payment_desc',
            ],
        ];
    }

    public function registerPermissions()
    {
        return [
            'Payment.Cod'             => [
                'description' => 'Ability to manage cash on delivery payment',
                'action'      => ['manage'],
            ],
            'Payment.PaypalExpress'   => [
                'action'      => ['manage'],
                'description' => 'Ability to manage paypal express payment',
            ],
            'Payment.AuthorizeNetAIM' => [
                'action'      => ['manage'],
                'description' => 'Ability to manage Authorize.Net payment extension',
            ],
            'Payment.Stripe'          => [
                'action'      => ['manage'],
                'description' => 'Ability to manage Stripe payment extension',
            ],
        ];
    }
}
