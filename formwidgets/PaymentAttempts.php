<?php

namespace Igniter\PayRegister\FormWidgets;

use Admin\Classes\BaseFormWidget;
use Admin\Models\Payment_logs_model;
use Admin\Traits\FormModelWidget;
use Admin\Traits\ValidatesForm;
use Admin\Widgets\Form;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Flame\Location\Models\AbstractLocation;

class PaymentAttempts extends BaseFormWidget
{
    use FormModelWidget;
    use ValidatesForm;

    /**
     * @var Orders_model Form model object.
     */
    public $model;

    public $form;

    public $formTitle = 'admin::lang.locations.text_title_schedule';

    public function initialize()
    {
        $this->fillFromConfig([
            'form',
        ]);
    }

    public function render()
    {
        $this->prepareVars();

        $widget = $this->makeFormWidget('Admin\FormWidgets\DataTable', $this->formField, [
            'columns' => [
                'date_added_since' => [
                    'title' => 'lang:admin::lang.orders.column_time_date',
                ],
                'payment_name' => [
                    'title' => 'lang:admin::lang.orders.label_payment_method',
                ],
                'message' => [
                    'title' => 'lang:admin::lang.orders.column_comment',
                ],
                'is_refundable' => [
                    'title' => 'Refund',
                    'partial' => 'extensions/igniter/payregister/views/partials/refund_button',
                ]
            ]
        ]);
        
        //var_dump($this->controller); exit();
        
        $widget->bindToController($this->controller);
        
        return $widget->render();
    }

    public function onLoadRecord()
    {
        $paymentLogId = post('recordId');

        $model = Payment_logs_model::find($paymentLogId);

        if (!$model)
           throw new ApplicationException('Record not found');

        $formTitle = sprintf(lang($this->formTitle), $paymentLogId);

        return $this->makePartial('recordeditor/form', [
            'formRecordId' => $paymentLogId,
            'formTitle' => $formTitle,
            'formWidget' => $this->makeRefundFormWidget($model),
        ]);
    }

    public function loadAssets()
    {
        $this->addJs('app/admin/formwidgets/recordeditor/assets/js/recordeditor.modal.js', 'recordeditor-modal-js');
        $this->addJs('extensions/igniter/payregister/formwidgets/paymentattempts/js/paymentattempts.js', 'paymentattempts-js');
    }

    public function prepareVars()
    {
        $this->vars['field'] = $this->formField;
    }

    protected function makeRefundFormWidget($model)
    {
        $widgetConfig = is_string($this->form) ? $this->loadConfig($this->form, ['form'], 'form') : $this->form;
        $widgetConfig['model'] = $model;
        $widgetConfig['alias'] = $this->alias.'Form'.'payment-attempt';
        $widgetConfig['arrayName'] = $this->formField->arrayName.'[paymentAttempt]';
        $widgetConfig['context'] = 'edit';
        $widget = $this->makeWidget(Form::class, $widgetConfig);

        $widget->bindToController();
        $widget->previewMode = $this->previewMode;

        return $widget;
    }
}
