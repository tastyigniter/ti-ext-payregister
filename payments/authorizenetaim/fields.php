<?php

return [
    'fields' => [
        'api_login_id' => [
            'label' => 'lang:igniter.payregister::default.authorize_net_aim.label_api_login_id',
            'type' => 'text',
        ],
        'client_key' => [
            'label' => 'lang:igniter.payregister::default.authorize_net_aim.label_client_key',
            'type' => 'text',
        ],
        'transaction_key' => [
            'label' => 'lang:igniter.payregister::default.authorize_net_aim.label_transaction_key',
            'type' => 'text',
        ],
        'transaction_mode' => [
            'label' => 'lang:igniter.payregister::default.authorize_net_aim.label_transaction_mode',
            'type' => 'radiotoggle',
            'default' => 'test',
            'options' => [
                'test' => 'lang:igniter.payregister::default.authorize_net_aim.text_test',
                'test_live' => 'lang:igniter.payregister::default.authorize_net_aim.text_test_live',
                'live' => 'lang:igniter.payregister::default.authorize_net_aim.text_go_live',
            ],
        ],
        'transaction_type' => [
            'label' => 'lang:igniter.payregister::default.authorize_net_aim.label_transaction_type',
            'type' => 'radiotoggle',
            'default' => 'auth_capture',
            'options' => [
                'auth_only' => 'lang:igniter.payregister::default.authorize_net_aim.text_auth_only',
                'auth_capture' => 'lang:igniter.payregister::default.authorize_net_aim.text_auth_capture',
            ],
        ],
        'accepted_cards' => [
            'label' => 'lang:igniter.payregister::default.authorize_net_aim.label_accepted_cards',
            'type' => 'select',
            'multiOption' => TRUE,
            'default' => ['visa', 'mastercard', 'american_express', 'jcb', 'diners_club'],
            'options' => 'getAcceptedCards',
        ],
        'order_fee_type' => [
            'label' => 'lang:igniter.payregister::default.label_order_fee_type',
            'type' => 'radiotoggle',
            'span' => 'left',
            'default' => 1,
            'options' => [
                1 => 'lang:admin::lang.coupons.text_fixed_amount',
                2 => 'lang:admin::lang.coupons.text_percentage',
            ],
        ],
        'order_fee' => [
            'label' => 'lang:igniter.payregister::default.label_order_fee',
            'type' => 'number',
            'span' => 'right',
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
            'options' => ['Admin\Models\Statuses_model', 'getDropdownOptionsForOrder'],
            'comment' => 'lang:igniter.payregister::default.help_order_status',
        ],
    ],
];