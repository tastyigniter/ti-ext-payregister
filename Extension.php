<?php namespace SamPoyigi\PayRegister;

use System\Classes\BaseExtension;

class Extension extends BaseExtension
{
    public function registerPaymentGateways()
    {
        return [
            'SamPoyigi\PayRegister\Payments\Cod'                             => [
                'code'        => 'cod',
                'name'        => 'lang:cod::default.text_payment_title',
                'description' => 'lang:cod::default.text_payment_desc',
            ],
            'SamPoyigi\PayRegister\Payments\PaypalExpress'       => [
                'code'        => 'paypal_express',
                'name'        => 'lang:paypal_express::default.text_payment_title',
                'description' => 'lang:paypal_express::default.text_payment_desc',
            ],
            'SamPoyigi\PayRegister\Payments\AuthorizeNetAim' => [
                'code'        => 'authorize_net_aim',
                'name'        => 'lang:authorize_net_aim::default.text_payment_title',
                'description' => 'lang:authorize_net_aim::default.text_payment_desc',
            ],
            'SamPoyigi\PayRegister\Payments\Stripe'                       => [
                'code'        => 'stripe',
                'name'        => 'lang:stripe::default.text_payment_title',
                'description' => 'lang:stripe::default.text_payment_desc',
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
