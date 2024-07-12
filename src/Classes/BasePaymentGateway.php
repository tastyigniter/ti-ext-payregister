<?php

namespace Igniter\PayRegister\Classes;

use Igniter\Flame\Database\Model;
use Igniter\Flame\Support\Facades\File;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\Main\Classes\ThemeManager;
use Igniter\PayRegister\Concerns\WithApplicableFee;
use Igniter\System\Actions\ModelAction;
use Illuminate\Support\Facades\URL;

/**
 * Base Payment Gateway Class
 */
class BasePaymentGateway extends ModelAction
{
    use EventEmitter;
    use WithApplicableFee;

    protected $orderModel = \Igniter\Cart\Models\Order::class;

    protected $orderStatusModel = \Igniter\Admin\Models\Status::class;

    protected $configFields = [];

    protected $configValidationAttributes = [];

    protected $configValidationMessages = [];

    protected $configRules = [];

    public static ?string $paymentFormView = null;

    public function __construct(?Model $model = null)
    {
        parent::__construct($model);

        $reflector = new \ReflectionClass($calledClass = get_called_class());
        $this->configPath[] = dirname($reflector->getFileName()).'/'.basename(File::normalizePath(strtolower($calledClass)));

        $formConfig = $this->loadConfig($this->defineFieldsConfig(), ['fields']);
        $this->configFields = array_get($formConfig, 'fields');
        $this->configRules = array_get($formConfig, 'rules', []);
        $this->configValidationAttributes = array_get($formConfig, 'validationAttributes', []);
        $this->configValidationMessages = array_get($formConfig, 'validationMessages', []);

        if (!$model) {
            return;
        }

        $this->initialize($model);
    }

    /**
     * Initialize method called when the payment gateway is first loaded
     * with an existing model.
     * @return array
     */
    public function initialize($host)
    {
        // Set default data
        if (!$host->exists) {
            $this->initConfigData($host);
        }
    }

    /**
     * Initializes configuration data when the payment method is first created.
     *
     * @param Model $host
     */
    public function initConfigData($host) {}

    /**
     * Extra field configuration for the payment type.
     */
    public function defineFieldsConfig()
    {
        return 'fields';
    }

    /**
     * Returns the form configuration used by this payment type.
     */
    public function getConfigFields()
    {
        return $this->configFields;
    }

    /**
     * Returns the form validation rules used by this payment type.
     */
    public function getConfigRules()
    {
        return $this->configRules;
    }

    /**
     * Returns the form validation attributes used by this model.
     */
    public function getConfigValidationAttributes()
    {
        return $this->configValidationAttributes;
    }

    /**
     * Returns the form validation messages used by this model.
     */
    public function getConfigValidationMessages()
    {
        return $this->configValidationMessages;
    }

    /**
     * Registers a entry page with specific URL. For example,
     * PayPal needs a landing page for the auto-return feature.
     * Important! Payment module access point names should have a prefix.
     * @return array Returns an array containing page URLs and methods to call for each URL:
     * return ['paypal_return'=>'processPaypalReturn']. The processing methods must be declared
     * in the payment type class. Processing methods must accept one parameter - an array of URL segments
     * following the access point. For example, if URL is /paypal_return/12/34 an array
     * ['12', '34'] will be passed to processPaypalReturn method.
     */
    public function registerEntryPoints()
    {
        return [];
    }

    /**
     * Utility function, creates a link to a registered entry point.
     *
     * @param string $code Key used to define the entry point
     *
     * @return string
     */
    public function makeEntryPointUrl($code)
    {
        return URL::to('ti_payregister/'.$code);
    }

    /**
     * This method should return TRUE if the gateway completes the payment on the client's browsers.
     * Allows the system to take extra steps during checkout before  completing the payment
     */
    public function completesPaymentOnClient()
    {
        return false;
    }

    protected function validatePaymentMethod($order, $host)
    {
        $this->validateApplicableFee($order, $host);
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data Posted payment form data.
     * @param Model $host Type model object containing configuration fields values.
     * @param Model $order Order model object.
     */
    public function processPaymentForm($data, $host, $order) {}

    /**
     * Executed when this gateway is rendered on the checkout page.
     */
    public function beforeRenderPaymentForm($host, $controller) {}

    public function getPaymentFormViewName()
    {
        $themeCode = resolve(ThemeManager::class)->getActiveThemeCode();
        if (view()->exists($viewName = $themeCode.'::_partials.payregister.'.$this->model->code)) {
            return $viewName;
        }

        if (!$viewName = static::$paymentFormView) {
            $viewName = strtolower(str_before($this->model->class_name, '\\').'.'.str_before(str_after($this->model->class_name, '\\'), '\\'));
            $viewName .= '::'.$this->model->code.'.payment_form';
        }

        return view()->exists($viewName) ? $viewName : null;
    }

    /**
     * @return \Igniter\PayRegister\Models\Payment
     */
    public function getHostObject()
    {
        return $this->model;
    }

    /**
     * Creates an instance of the order model
     */
    protected function createOrderModel()
    {
        $class = '\\'.ltrim($this->orderModel, '\\');

        return new $class();
    }

    /**
     * Creates an instance of the order status model
     */
    protected function createOrderStatusModel()
    {
        $class = '\\'.ltrim($this->orderStatusModel, '\\');

        return new $class();
    }
}
