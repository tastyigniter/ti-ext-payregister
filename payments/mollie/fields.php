<?php

return [
    'fields' => [
        'transaction_mode' => [
            'label' => 'lang:igniter.payregister::default.mollie.label_transaction_mode',
            'type' => 'radio',
            'default' => 'test',
            'options' => [
                'test' => 'lang:igniter.payregister::default.mollie.text_test',
                'live' => 'lang:igniter.payregister::default.mollie.text_live',
            ],
        ],
        'live_api_key' => [
            'label' => 'lang:igniter.payregister::default.mollie.label_live_api_key',
            'type' => 'text',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[live]',
            ],
        ],
        'test_api_key' => [
            'label' => 'lang:igniter.payregister::default.mollie.label_test_api_key',
            'type' => 'text',
            'trigger' => [
                'action' => 'show',
                'field' => 'transaction_mode',
                'condition' => 'value[test]',
            ],
        ],
        'order_total' => [
            'label' => 'lang:igniter.payregister::default.label_order_total',
            'type' => 'currency',
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