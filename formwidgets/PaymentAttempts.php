<?php

namespace Igniter\PayRegister\FormWidgets;

use Admin\Classes\BaseFormWidget;
use Admin\Models\Payment_logs_model;
use Admin\Traits\FormModelWidget;
use Admin\Traits\ValidatesForm;
use Admin\Widgets\Form;
use Igniter\Flame\Exception\ApplicationException;

class PaymentAttempts extends BaseFormWidget
{
    use FormModelWidget;
    use ValidatesForm;

    /**
     * @var Orders_model Form model object.
     */
    public $model;

    public $form;

    public $columns;

    public $formTitle = 'igniter.payregister::default.text_refund_title';

    public function initialize()
    {
        $this->fillFromConfig([
            'form',
            'columns',
        ]);
    }

    public function render()
    {
        $this->prepareVars();

        return $this->makePartial('paymentattempts/paymentattempts');
    }

    public function getSaveValue($value)
    {
        return FormField::NO_SAVE_DATA;
    }

    public function onLoadRecord()
    {
        $paymentLogId = post('recordId');

        $model = Payment_logs_model::find($paymentLogId);

        if (!$model)
            throw new ApplicationException('Record not found');

        $formTitle = sprintf(lang($this->formTitle), currency_format($model->order->order_total));

        return $this->makePartial('~/app/admin/formwidgets/recordeditor/form', [
            'formRecordId' => $paymentLogId,
            'formTitle' => $formTitle,
            'formWidget' => $this->makeRefundFormWidget($model),
        ]);
    }

    public function onSaveRecord()
    {
        $paymentLogId = post('recordId');

        $paymentLog = Payment_logs_model::find($paymentLogId);

        $paymentMethod = $this->model->payment_method;

        $widget = $this->makeRefundFormWidget($paymentLog);
        $data = $widget->getSaveData();

        $this->validate($data, $widget->config['rules']);

        $paymentMethod->processRefundForm($data, $this->model, $paymentLog);
    }

    public function loadAssets()
    {
        $this->addJs('~/app/admin/formwidgets/recordeditor/assets/js/recordeditor.modal.js', 'recordeditor-modal-js');
        $this->addJs('$/igniter/payregister/formwidgets/paymentattempts/js/paymentattempts.js', 'paymentattempts-js');
    }

    public function prepareVars()
    {
        $this->vars['field'] = $this->formField;
        $this->vars['dataTableWidget'] = $this->makeDataTableWidget();
    }

    protected function makeDataTableWidget()
    {
        $field = clone $this->formField;

        $fieldConfig = $field->config;
        $fieldConfig['type'] = $fieldConfig['widget'] = 'datatable';
        $widgetConfig = $this->makeConfig($fieldConfig);

        $widgetConfig['model'] = $this->model;
        $widgetConfig['data'] = $this->data;
        $widgetConfig['alias'] = $this->alias.'Form'.'payment-attempt';
        $widgetConfig['arrayName'] = $this->formField->arrayName.'[paymentAttempt]';

        $widget = $this->makeFormWidget('Admin\FormWidgets\DataTable', $field, $widgetConfig);
        $widget->bindToController();
        $widget->previewMode = $this->previewMode;

        return $widget;
    }

    protected function makeRefundFormWidget($model)
    {
        $widgetConfig = is_string($this->form) ? $this->loadConfig($this->form, ['form'], 'form') : $this->form;
        $widgetConfig['model'] = $model;
        $widgetConfig['data'] = array_merge($model->toArray(), ['refund_amount' => $model->order->order_total]);
        $widgetConfig['alias'] = $this->alias.'Form'.'payment-attempt';
        $widgetConfig['arrayName'] = $this->formField->arrayName.'[paymentAttempt]';
        $widgetConfig['context'] = 'edit';
        $widget = $this->makeWidget(Form::class, $widgetConfig);

        $widget->bindToController();
        $widget->previewMode = $this->previewMode;

        return $widget;
    }
}
