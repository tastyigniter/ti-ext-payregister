<?php

namespace Igniter\PayRegister\Http\Controllers;

use Exception;
use Igniter\Admin\Facades\AdminMenu;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\PaymentGateways;
use Igniter\PayRegister\Models\Payment;
use Igniter\System\Helpers\ValidationHelper;
use Illuminate\Support\Arr;

class Payments extends \Igniter\Admin\Classes\AdminController
{
    public $implement = [
        \Igniter\Admin\Http\Actions\ListController::class,
        \Igniter\Admin\Http\Actions\FormController::class,
    ];

    public $listConfig = [
        'list' => [
            'model' => \Igniter\PayRegister\Models\Payment::class,
            'title' => 'lang:igniter.payregister::default.text_title',
            'emptyMessage' => 'lang:igniter.payregister::default.text_empty',
            'defaultSort' => ['updated_at', 'DESC'],
            'configFile' => 'payment',
        ],
    ];

    public $formConfig = [
        'name' => 'lang:igniter.payregister::default.text_form_name',
        'model' => \Igniter\PayRegister\Models\Payment::class,
        'create' => [
            'title' => 'lang:igniter::admin.form.create_title',
            'redirect' => 'payments/edit/{code}',
            'redirectClose' => 'payments',
            'redirectNew' => 'payments/create',
        ],
        'edit' => [
            'title' => 'lang:igniter::admin.form.edit_title',
            'redirect' => 'payments/edit/{code}',
            'redirectClose' => 'payments',
            'redirectNew' => 'payments/create',
        ],
        'delete' => [
            'redirect' => 'payments',
        ],
        'configFile' => 'payment',
    ];

    protected $requiredPermissions = 'Admin.Payments';

    protected $gateway;

    public static function getSlug()
    {
        return 'payments';
    }

    public function __construct()
    {
        parent::__construct();

        AdminMenu::setContext('payments', 'sales');
    }

    public function index()
    {
        Payment::syncAll();

        $this->asExtension('ListController')->index();
    }

    public function index_onSetDefault($context = null)
    {
        if (Payment::updateDefault(post('default'))) {
            flash()->success(sprintf(lang('igniter::admin.alert_success'), lang('igniter.payregister::default.alert_set_default')));
        }

        return $this->refreshList('list');
    }

    public function listOverrideColumnValue($record, $column, $alias = null)
    {
        if ($column->type == 'button' && $column->columnName == 'default') {
            $column->iconCssClass = $record->is_default ? 'fa fa-star' : 'fa fa-star-o';
        }
    }

    /**
     * Finds a Model record by its primary identifier, used by edit actions. This logic
     * can be changed by overriding it in the controller.
     *
     * @param string $paymentCode
     *
     * @return Model
     * @throws \Exception
     */
    public function formFindModelObject($paymentCode = null)
    {
        if (!strlen($paymentCode)) {
            throw new Exception(lang('igniter.payregister::default.alert_setting_missing_id'));
        }

        $model = $this->formCreateModelObject();

        // Prepare query and find model record
        $query = $model->newQuery();
        $this->fireEvent('admin.controller.extendFormQuery', [$query]);
        $this->formExtendQuery($query);
        $result = $query->whereCode($paymentCode)->first();

        if (!$result) {
            throw new Exception(sprintf(lang('igniter::admin.form.not_found'), $paymentCode));
        }

        $result = $this->formExtendModel($result) ?: $result;

        return $result;
    }

    protected function getGateway($code)
    {
        if ($this->gateway !== null) {
            return $this->gateway;
        }

        if (!$gateway = resolve(PaymentGateways::class)->findGateway($code)) {
            throw new Exception(sprintf(lang('igniter.payregister::default.alert_code_not_found'), $code));
        }

        return $this->gateway = $gateway;
    }

    public function formExtendModel($model)
    {
        if (!$model->exists) {
            $model->applyGatewayClass();
        }

        return $model;
    }

    public function formExtendFieldsBefore($form)
    {
        $model = $form->model;
        if ($model->exists) {
            $form->tabs['fields'] = array_merge($form->tabs['fields'] ?? [], $model->getConfigFields());
        }

        if ($form->context != 'create') {
            array_set($form->fields, 'code.disabled', true);
        }
    }

    public function formBeforeCreate($model)
    {
        if (!strlen($code = post('Payment.payment'))) {
            throw new ApplicationException(lang('igniter.payregister::default.alert_invalid_code'));
        }

        $paymentGateway = resolve(PaymentGateways::class)->findGateway($code);

        $model->class_name = $paymentGateway['class'];
    }

    public function formAfterUpdate($model)
    {
        if ($model->status) {
            Payment::syncAll();
        }
    }

    public function formValidate($model, $form)
    {
        $rules = [
            'payment' => ['sometimes', 'required', 'alpha_dash'],
            'name' => ['required', 'min:2', 'max:255'],
            'code' => ['sometimes', 'required', 'alpha_dash', 'unique:payments,code'],
            'priority' => ['required', 'integer'],
            'description' => ['max:255'],
            'is_default' => ['required', 'integer'],
            'status' => ['required', 'integer'],
        ];

        $messages = [];

        $attributes = [
            'payment' => lang('igniter.payregister::default.label_payments'),
            'name' => lang('igniter::admin.label_name'),
            'code' => lang('igniter.payregister::default.label_code'),
            'priority' => lang('igniter.payregister::default.label_priority'),
            'description' => lang('igniter::admin.label_description'),
            'is_default' => lang('igniter.payregister::default.label_default'),
            'status' => lang('lang:igniter::admin.label_status'),
        ];

        if ($form->model->exists) {
            $parsedRules = ValidationHelper::prepareRules($form->model->getConfigRules());

            if ($mergeRules = Arr::get($parsedRules, 'rules', $parsedRules)) {
                $rules = array_merge($rules, $mergeRules);
            }

            if ($mergeMessages = $form->model->getConfigValidationMessages()) {
                $messages = array_merge($messages, $mergeMessages);
            }

            if ($mergeAttributes = Arr::get($parsedRules, 'attributes', $form->model->getConfigValidationAttributes())) {
                $attributes = array_merge($attributes, $mergeAttributes);
            }
        }

        return $this->validatePasses($form->getSaveData(), $rules, $messages, $attributes);
    }
}
