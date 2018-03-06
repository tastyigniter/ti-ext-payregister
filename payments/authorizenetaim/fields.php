<?php

return [
    'fields' => [
        'api_login_id'     => [
            'label'   => 'lang:sampoyigi.payregister::default.authorize_net_aim.label_api_login_id',
            'type'    => 'text',
        ],
        'transaction_key'  => [
            'label'   => 'lang:sampoyigi.payregister::default.authorize_net_aim.label_transaction_key',
            'type'    => 'text',
        ],
        'transaction_mode' => [
            'label'   => 'lang:sampoyigi.payregister::default.authorize_net_aim.label_transaction_mode',
            'type'    => 'radio',
            'default'    => 'test',
            'options' => [
                'live'      => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_go_live',
                'test'      => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_test',
                'test_live' => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_test_live',
            ],
        ],
        'transaction_type' => [
            'label'   => 'lang:sampoyigi.payregister::default.authorize_net_aim.label_transaction_type',
            'type'    => 'radio',
            'default'    => 'auth_capture',
            'options' => [
                'auth_only'    => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_auth_only',
                'auth_capture' => 'lang:sampoyigi.payregister::default.authorize_net_aim.text_auth_capture',
            ],
        ],
        'accepted_cards' => [
            'label'   => 'lang:sampoyigi.payregister::default.authorize_net_aim.label_accepted_cards',
            'type'    => 'select',
            'multiOption'    => true,
            'default'    => ['visa', 'mastercard', 'american_express', 'jcb', 'diners_club'],
            'options'    => 'getAcceptedCards',
        ],
        'order_total'      => [
            'label'   => 'lang:sampoyigi.payregister::default.label_order_total',
            'type'    => 'number',
            'comment' => 'lang:sampoyigi.payregister::default.help_order_total',
        ],
        'order_status'     => [
            'label'   => 'lang:sampoyigi.payregister::default.label_order_status',
            'type'    => 'select',
            'options'    => ['Admin\Models\Statuses_model', 'getDropdownOptionsForOrder'],
            'comment' => 'lang:sampoyigi.payregister::default.help_order_status',
        ],
    ],
];