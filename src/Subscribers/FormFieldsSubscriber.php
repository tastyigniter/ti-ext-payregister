<?php

namespace Igniter\PayRegister\Subscribers;

use Igniter\Admin\Widgets\Form;
use Igniter\Cart\Models\Order;
use Igniter\Local\Events\LocationDefineOptionsFieldsEvent;
use Igniter\Local\Requests\LocationRequest;
use Igniter\PayRegister\Models\Payment;
use Igniter\System\Classes\FormRequest;
use Illuminate\Contracts\Events\Dispatcher;

class FormFieldsSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            LocationDefineOptionsFieldsEvent::class => 'handle',
            'system.formRequest.extendValidator' => 'handleValidation',
            'admin.form.extendFieldsBefore' => 'addPaymentLogsField',
        ];
    }

    public function handle(LocationDefineOptionsFieldsEvent $event): array
    {
        return [
            'payments' => [
                'label' => 'lang:igniter.payregister::default.label_payments',
                'accordion' => 'lang:igniter.local::default.text_tab_general_options',
                'type' => 'checkboxlist',
                'options' => [Payment::class, 'listDropdownOptions'],
                'commentAbove' => 'lang:igniter.payregister::default.help_payments',
                'placeholder' => 'lang:igniter.payregister::default.help_no_payments',
            ],
        ];
    }

    public function handleValidation(FormRequest $formRequest, object $dataHolder)
    {
        if (!$formRequest instanceof LocationRequest) {
            return;
        }

        $dataHolder->attributes = array_merge($dataHolder->attributes, [
            'options.payments.*' => lang('igniter.payregister::default.label_payments'),
        ]);

        $dataHolder->rules = array_merge($dataHolder->rules, [
            'options.payments.*' => ['string'],
        ]);
    }

    public function addPaymentLogsField(Form $form)
    {
        if ($form->model instanceof Order) {
            $form->tabs['fields']['payment_logs'] = [
                'tab' => 'lang:igniter.payregister::default.text_payment_logs',
                'type' => 'paymentattempts',
                'useAjax' => true,
                'defaultSort' => ['payment_log_id', 'desc'],
                'form' => 'igniter.payregister::/models/config/paymentlog',
                'columns' => [
                    'date_added_since' => [
                        'title' => 'lang:igniter.cart::default.orders.column_time_date',
                    ],
                    'payment_name' => [
                        'title' => 'lang:igniter.cart::default.orders.label_payment_method',
                    ],
                    'message' => [
                        'title' => 'lang:igniter.cart::default.orders.column_comment',
                    ],
                    'is_refundable' => [
                        'title' => 'Action',
                        'partial' => 'igniter.payregister::_partials/refund_button',
                    ],
                ],
            ];
        }
    }
}
