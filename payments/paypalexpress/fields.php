<?php

return [
    'fields' => [
        'api_user' => [
            'label' => 'lang:igniter.payregister::default.paypal.label_api_user',
            'type' => 'text',
        ],
        'api_pass' => [
            'label' => 'lang:igniter.payregister::default.paypal.label_api_pass',
            'type' => 'text',
        ],
        'api_signature' => [
            'label' => 'lang:igniter.payregister::default.paypal.label_api_signature',
            'type' => 'text',
        ],
        'api_mode' => [
            'label' => 'lang:igniter.payregister::default.paypal.label_api_mode',
            'type' => 'radiotoggle',
            'default' => 'sandbox',
            'options' => [
                'sandbox' => 'lang:igniter.payregister::default.paypal.text_sandbox',
                'live' => 'lang:igniter.payregister::default.paypal.text_go_live',
            ],
        ],
        'api_action' => [
            'label' => 'lang:igniter.payregister::default.paypal.label_api_action',
            'type' => 'radiotoggle',
            'default' => 'sale',
            'options' => [
                'sale' => 'lang:igniter.payregister::default.paypal.text_sale',
                'authorization' => 'lang:igniter.payregister::default.paypal.text_authorization',
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
            'comment' => 'lang:igniter.payregister::default.help_order_total',
        ],
        'order_status' => [
            'label' => 'lang:igniter.payregister::default.label_order_status',
            'type' => 'select',
            'options' => [\Admin\Models\Statuses_model::class, 'getDropdownOptionsForOrder'],
            'comment' => 'lang:igniter.payregister::default.help_order_status',
        ],
    ],
    'rules' => [
        ['api_user', 'lang:igniter.payregister::default.paypal.label_api_user', 'string'],
        ['api_pass', 'lang:igniter.payregister::default.paypal.label_api_pass', 'string'],
        ['api_signature', 'lang:igniter.payregister::default.paypal.label_api_signature', 'string'],
        ['api_mode', 'lang:igniter.payregister::default.paypal.label_api_mode', 'string'],
        ['api_action', 'lang:igniter.payregister::default.paypal.label_api_action', 'string'],
        ['order_fee_type', 'lang:igniter.payregister::default.label_order_fee_type', 'integer'],
        ['order_fee', 'lang:igniter.payregister::default.label_order_fee', 'numeric'],
        ['order_total', 'lang:igniter.payregister::default.label_order_total', 'numeric'],
        ['order_status', 'lang:igniter.payregister::default.label_order_status', 'integer'],
    ],
];
