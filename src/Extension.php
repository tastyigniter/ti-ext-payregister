<?php

namespace Igniter\PayRegister;

use Igniter\PayRegister\Classes\PaymentGateways;
use Igniter\PayRegister\Listeners\CaptureAuthorizedPayment;
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
        'admin.statusHistory.added' => [
            CaptureAuthorizedPayment::class,
        ],
    ];

    protected $observers = [
        Payment::class => PaymentObserver::class,
    ];

    public array $singletons = [
        PaymentGateways::class,
    ];

    public function registerPaymentGateways(): array
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

    public function registerFormWidgets(): array
    {
        return [
            \Igniter\PayRegister\FormWidgets\PaymentAttempts::class => [
                'label' => 'Payment Attempts',
                'code' => 'paymentattempts',
            ],
        ];
    }

    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label' => lang('igniter.payregister::default.text_side_menu'),
                'description' => 'Manage payment gateways and settings',
                'icon' => 'fa fa-cash-register',
                'priority' => -1,
                'permissions' => ['Admin.Payments'],
                'url' => admin_url('payments'),
            ],
        ];
    }

    public function registerPermissions(): array
    {
        return [
            'Admin.Payments' => [
                'label' => 'igniter.payregister::default.help_permission',
                'group' => 'igniter.payregister::default.text_permission_group',
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

        Event::listen('main.theme.activated', function() {
            Payment::syncAll();
        });

        Relation::enforceMorphMap([
            'payment_logs' => \Igniter\PayRegister\Models\PaymentLog::class,
            'payments' => \Igniter\PayRegister\Models\Payment::class,
        ]);
    }
}
