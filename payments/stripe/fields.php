<?php

return [
    'fields' => [
        'transaction_mode' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_transaction_mode',
            'type' => 'radio',
            'default' => 'test',
            'options' => [
                'live' => 'lang:igniter.payregister::default.stripe.text_live',
                'test' => 'lang:igniter.payregister::default.stripe.text_test',
            ],
        ],
        'live_secret_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_live_secret_key',
            'type' => 'text',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[live]',
            ],
        ],
        'live_publishable_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_live_publishable_key',
            'type' => 'text',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[live]',
            ],
        ],
        'test_secret_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_test_secret_key',
            'type' => 'text',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[test]',
            ],
        ],
        'test_publishable_key' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_test_publishable_key',
            'type' => 'text',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[test]',
            ],
        ],
        'force_ssl' => [
            'label' => 'lang:igniter.payregister::default.stripe.label_force_ssl',
            'type' => 'switch',
            'default' => TRUE,
        ],
        'order_total' => [
            'label' => 'lang:igniter.payregister::default.label_order_total',
            'type' => 'number',
            'comment' => 'lang:igniter.payregister::default.help_order_total',
        ],
        'order_status' => [
            'label' => 'lang:igniter.payregister::default.label_order_status',
            'type' => 'select',
            'options' => ['Admin\Models\Statuses_model', 'getDropdownOptionsForOrder'],
            'comment' => 'lang:igniter.payregister::default.help_order_status',
        ],
    ],
];