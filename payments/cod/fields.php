<?php

return [
    'fields' => [
        'order_total'  => [
            'label'   => 'lang:sampoyigi.payregister::default.label_order_total',
            'type'    => 'number',
            'comment' => 'lang:sampoyigi.payregister::default.help_order_total',
        ],
        'order_status' => [
            'label'   => 'lang:sampoyigi.payregister::default.label_order_status',
            'type'    => 'select',
            'options' => ['Admin\Models\Statuses_model', 'getDropdownOptionsForOrder'],
            'comment' => 'lang:sampoyigi.payregister::default.help_order_status',
        ],
    ],
];