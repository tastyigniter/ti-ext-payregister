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
            'type' => 'radio',
            'default' => 'sandbox',
            'options' => [
                'sandbox' => 'lang:igniter.payregister::default.paypal.text_sandbox',
                'live' => 'lang:igniter.payregister::default.paypal.text_go_live',
            ],
        ],
        'api_action' => [
            'label' => 'lang:igniter.payregister::default.paypal.label_api_action',
            'type' => 'radio',
            'default' => 'sale',
            'options' => [
                'sale' => 'lang:igniter.payregister::default.paypal.text_sale',
                'authorization' => 'lang:igniter.payregister::default.paypal.text_authorization',
            ],
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