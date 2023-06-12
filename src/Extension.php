<?php

namespace Igniter\PayRegister;

use Igniter\PayRegister\Classes\PaymentGateways;
use Igniter\PayRegister\Listeners\UpdatePaymentIntentSessionOnCheckout;
use Igniter\PayRegister\Models\Observers\PaymentObserver;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Subscribers\FormFieldsSubscriber;
use Igniter\System\Classes\BaseExtension;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;

class Extension extends BaseExtension
{
    protected $listen = [
        'igniter.checkout.afterSaveOrder' => [
            UpdatePaymentIntentSessionOnCheckout::class,
        ],
    ];

    protected $observers = [
        Payment::class => PaymentObserver::class,
    ];

    public $singletons = [
        PaymentGateways::class,
    ];

    public function registerPaymentGateways()
    {
        return [
            \Igniter\PayRegister\Payments\Cod::class => [
                'code' => 'cod',
                'name' => 'lang:igniter.payregister::default.cod.text_payment_title',
                'description' => 'lang:igniter.payregister::default.cod.text_payment_desc',
            ],
            \Igniter\PayRegister\Payments\PaypalExpress::class => [
                'code' => 'paypalexpress',
                'name' => 'lang:igniter.payregister::default.paypal.text_payment_title',
                'description' => 'lang:igniter.payregister::default.paypal.text_payment_desc',
            ],
            \Igniter\PayRegister\Payments\AuthorizeNetAim::class => [
                'code' => 'authorizenetaim',
                'name' => 'lang:igniter.payregister::default.authorize_net_aim.text_payment_title',
                'description' => 'lang:igniter.payregister::default.authorize_net_aim.text_payment_desc',
            ],
            \Igniter\PayRegister\Payments\Stripe::class => [
                'code' => 'stripe',
                'name' => 'lang:igniter.payregister::default.stripe.text_payment_title',
                'description' => 'lang:igniter.payregister::default.stripe.text_payment_desc',
            ],
            \Igniter\PayRegister\Payments\Mollie::class => [
                'code' => 'mollie',
                'name' => 'lang:igniter.payregister::default.mollie.text_payment_title',
                'description' => 'lang:igniter.payregister::default.mollie.text_payment_desc',
            ],
            \Igniter\PayRegister\Payments\Square::class => [
                'code' => 'square',
                'name' => 'lang:igniter.payregister::default.square.text_payment_title',
                'description' => 'lang:igniter.payregister::default.square.text_payment_desc',
            ],
        ];
    }

    public function registerFormWidgets()
    {
        return [
            \Igniter\PayRegister\FormWidgets\PaymentAttempts::class => [
                'label' => 'Payment Attempts',
                'code' => 'paymentattempts',
            ],
        ];
    }

    public function registerNavigation()
    {
        return [
            'sales' => [
                'child' => [
                    'payments' => [
                        'priority' => 50,
                        'class' => 'payments',
                        'href' => admin_url('payments'),
                        'title' => lang('igniter.payregister::default.text_side_menu'),
                        'permission' => 'Admin.Payments',
                    ],
                ],
            ],
        ];
    }

    public function registerPermissions()
    {
        return [
            'Admin.Payments' => [
                'label' => 'igniter.payregister::default.help_permission',
                'group' => 'sales',
            ],
        ];
    }

    public function registerOnboardingSteps()
    {
        return [
            'igniter.payregister::payments' => [
                'label' => 'igniter.payregister::default.onboarding_payments',
                'description' => 'igniter.payregister::default.help_onboarding_payments',
                'icon' => 'fa-credit-card',
                'url' => admin_url('payments'),
                'priority' => 35,
                'complete' => [\Igniter\PayRegister\Models\Payment::class, 'onboardingIsComplete'],
            ],
        ];
    }

    public function boot()
    {
        Event::subscribe(FormFieldsSubscriber::class);

        Event::listen('main.theme.activated', function () {
            Payment::syncAll();
        });

        Relation::enforceMorphMap([
            'payment_logs' => \Igniter\PayRegister\Models\PaymentLog::class,
            'payments' => \Igniter\PayRegister\Models\Payment::class,
        ]);
    }
}
