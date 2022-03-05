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
                'test' => 'lang:igniter.payregister::default.stripe.text_test',
                'live' => 'lang:igniter.payregister::default.stripe.text_live',
            ],
        ],
        'transaction_type' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_transaction_type',
            'type' => 'radiotoggle',
            'default' => 'auth_capture',
            'span' => 'right',
            'options' => [
                'auth_only' => 'lang:igniter.payregister::default.stripe.text_auth_only',
                'auth_capture' => 'lang:igniter.payregister::default.stripe.text_auth_capture',
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
        'order_fee_type' => [
            'label' => 'lang:igniter.payregister::default.label_order_fee_type',
            'type' => 'radiotoggle',
            'span' => 'left',
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
            'options' => [\Admin\Models\Statuses_model::class, 'getDropdownOptionsForOrder'],
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
        ['order_fee_type', 'lang:igniter.payregister::default.label_order_fee_type', 'integer'],
        ['order_fee', 'lang:igniter.payregister::default.label_order_fee', 'numeric'],
        ['order_total', 'lang:igniter.payregister::default.label_order_total', 'numeric'],
        ['order_status', 'lang:igniter.payregister::default.label_order_status', 'integer'],
    ],
];
