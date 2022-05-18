<?php

return [
    'fields' => [
        'setup' => [
            'type' => 'partial',
            'path' => '$/igniter/payregister/payments/stripe/info',
        ],
        'transaction_mode' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_transaction_mode',
            'type' => 'radiotoggle',
            'default' => 'test',
            'span' => 'left',
            'options' => [
                'live' => 'lang:igniter.payregister::default.stripe.text_live',
                'test' => 'lang:igniter.payregister::default.stripe.text_test',
            ],
        ],
        'transaction_type' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_transaction_type',
            'type' => 'radiotoggle',
            'default' => 'auth_capture',
            'span' => 'right',
            'options' => [
                'auth_capture' => 'lang:igniter.payregister::default.stripe.text_auth_capture',
                'auth_only' => 'lang:igniter.payregister::default.stripe.text_auth_only',
            ],
        ],
        'live_publishable_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_live_publishable_key',
            'type' => 'text',
            'span' => 'right',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[live]',
            ],
        ],
        'live_secret_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_live_secret_key',
            'type' => 'text',
            'span' => 'left',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[live]',
            ],
        ],
        'live_webhook_secret' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_live_webhook_secret',
            'type' => 'text',
            'span' => 'left',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[live]',
            ],
        ],
        'test_publishable_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_test_publishable_key',
            'type' => 'text',
            'span' => 'right',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[test]',
            ],
        ],
        'test_secret_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_test_secret_key',
            'type' => 'text',
            'span' => 'left',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[test]',
            ],
        ],
        'test_webhook_secret' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_test_webhook_secret',
            'type' => 'text',
            'span' => 'right',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[test]',
            ],
        ],
        'locale_code' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_locale_code',
            'type' => 'text',
            'span' => 'right',
        ],
        'order_fee_type' => [
            'label' => 'lang:igniter.payregister::default.label_order_fee_type',
            'type' => 'radiotoggle',
            'span' => 'right',
            'cssClass' => 'flex-width',
            'default' => 1,
            'options' => [
                1 => 'lang:admin::lang.menus.text_fixed_amount',
                2 => 'lang:admin::lang.menus.text_percentage',
            ],
        ],
        'order_fee' => [
            'label' => 'lang:igniter.payregister::default.label_order_fee',
            'type' => 'currency',
            'span' => 'right',
            'cssClass' => 'flex-width',
            'default' => 0,
            'comment' => 'lang:igniter.payregister::default.help_order_fee',
        ],
        'order_total' => [
            'label' => 'lang:igniter.payregister::default.label_order_total',
            'type' => 'currency',
            'span' => 'left',
            'comment' => 'lang:igniter.payregister::default.help_order_total',
        ],
        'order_status' => [
            'label' => 'lang:igniter.payregister::default.label_order_status',
            'type' => 'select',
            'options' => [\Igniter\Admin\Models\Status::class, 'getDropdownOptionsForOrder'],
            'span' => 'right',
            'comment' => 'lang:igniter.payregister::default.help_order_status',
        ],
    ],
    'rules' => [
        ['transaction_mode', 'lang:igniter.payregister::default.stripe.label_transaction_mode', 'string'],
        ['live_secret_key', 'lang:igniter.payregister::default.stripe.label_live_secret_key', 'string'],
        ['live_publishable_key', 'lang:igniter.payregister::default.stripe.label_live_publishable_key', 'string'],
        ['test_secret_key', 'lang:igniter.payregister::default.stripe.label_test_secret_key', 'string'],
        ['test_publishable_key', 'lang:igniter.payregister::default.stripe.label_test_publishable_key', 'string'],
        ['test_webhook_secret', 'lang:igniter.payregister::default.stripe.label_test_webhook_secret', 'string'],
        ['live_webhook_secret', 'lang:igniter.payregister::default.stripe.label_live_webhook_secret', 'string'],
        ['order_fee_type', 'lang:igniter.payregister::default.label_order_fee_type', 'integer'],
        ['order_fee', 'lang:igniter.payregister::default.label_order_fee', 'numeric'],
        ['order_total', 'lang:igniter.payregister::default.label_order_total', 'numeric'],
        ['order_status', 'lang:igniter.payregister::default.label_order_status', 'integer'],
    ],
];
