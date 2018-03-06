<?php

return [
    'fields' => [
        'api_user'     => [
            'label'   => 'lang:sampoyigi.payregister::default.paypal.label_api_user',
            'type'    => 'text',
        ],
        'api_pass'     => [
            'label'   => 'lang:sampoyigi.payregister::default.paypal.label_api_pass',
            'type'    => 'text',
        ],
        'api_signature'     => [
            'label'   => 'lang:sampoyigi.payregister::default.paypal.label_api_signature',
            'type'    => 'text',
        ],
        'api_mode' => [
            'label'   => 'lang:sampoyigi.payregister::default.paypal.label_api_mode',
            'type'    => 'radio',
            'default'    => 'sandbox',
            'options' => [
                'sandbox'      => 'lang:sampoyigi.payregister::default.paypal.text_sandbox',
                'live'      => 'lang:sampoyigi.payregister::default.paypal.text_go_live',
            ],
        ],
        'api_action' => [
            'label'   => 'lang:sampoyigi.payregister::default.paypal.label_api_action',
            'type'    => 'radio',
            'default'    => 'sale',
            'options' => [
                'sale'    => 'lang:sampoyigi.payregister::default.paypal.text_sale',
                'authorization' => 'lang:sampoyigi.payregister::default.paypal.text_authorization',
            ],
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