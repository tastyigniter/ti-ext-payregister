<?php

namespace Igniter\PayRegister\Classes;

use Igniter\Flame\Pagic\Model;
use Igniter\Flame\Support\Facades\File;
use Igniter\Main\Classes\ThemeManager;
use Igniter\PayRegister\Models\Payment;
use Igniter\System\Classes\ExtensionManager;
use Illuminate\Support\Facades\Response;

/**
 * Manages payment gateways
 */
class PaymentGateways
{
    /**
     * @var array Cache of registration callbacks.
     */
    private $callbacks = [];

    /**
     * @var array List of registered gateways.
     */
    private $gateways;

    /**
     * Returns payment gateway details based on its name.
     *
     * @return mixed|null
     */
    public function findGateway($name)
    {
        $gateways = $this->listGateways();
        if (empty($gateways[$name])) {
            return null;
        }

        return $gateways[$name];
    }

    /**
     * Returns a list of the payment gateway objects
     * @return \Igniter\PayRegister\Classes\BasePaymentGateway[]
     */
    public function listGatewayObjects()
    {
        $collection = [];
        $gateways = $this->listGateways();
        foreach ($gateways as $gateway) {
            $collection[$gateway['code']] = $gateway['object'];
        }

        return $collection;
    }

    /**
     * Returns a list of registered payment gateways.
     *
     * @return array Array keys are codes, values are payment gateways meta array.
     */
    public function listGateways()
    {
        if ($this->gateways == null) {
            $this->loadGateways();
        }

        if (!is_array($this->gateways)) {
            return [];
        }

        $result = [];
        foreach ($this->gateways as $gateway) {
            if (!class_exists($gateway['class'])) {
                continue;
            }

            $gatewayObj = new $gateway['class'];
            $result[$gateway['code']] = array_merge($gateway, [
                'object' => $gatewayObj,
            ]);
        }

        return $this->gateways = $result;
    }

    protected function loadGateways()
    {
        // Load manually registered components
        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        // Load extensions payment gateways
        $extensions = resolve(ExtensionManager::class)->getExtensions();
        foreach ($extensions as $id => $extension) {
            if (!method_exists($extension, 'registerPaymentGateways')) {
                continue;
            }

            $paymentGateways = $extension->registerPaymentGateways();
            if (!is_array($paymentGateways)) {
                continue;
            }

            $this->registerGateways($id, $paymentGateways);
        }
    }

    /**
     * Registers the payment gateways.
     * The argument is an array of the gateway classes.
     *
     * @param string $owner Specifies the gateways owner extension in the format extension_code.
     * @param array $classes An array of the payment gateway classes.
     */
    public function registerGateways($owner, array $classes)
    {
        if (!$this->gateways) {
            $this->gateways = [];
        }

        foreach ($classes as $classPath => $paymentGateway) {
            $code = $paymentGateway['code'] ?? strtolower(basename($classPath));

            $this->gateways[$code] = array_merge($paymentGateway, [
                'owner' => $owner,
                'class' => $classPath,
                'code' => $code,
            ]);
        }
    }

    /**
     * Manually registers a payment gateways.
     * Usage:
     * <pre>
     *   PaymentGateways::registerCallback(function($manager){
     *       $manager->registerGateways([...]);
     *   });
     * </pre>
     *
     * @param callable $callback A callable function.
     */
    public function registerCallback(callable $callback)
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Executes an entry point for registered gateways, defined in routes.php file.
     *
     * @param string $code Entry point code
     * @param string $uri Remaining uri parts
     *
     * @return \Illuminate\Http\Response
     */
    public static function runEntryPoint($code = null, $uri = null)
    {
        $params = explode('/', $uri);

        $gateways = Payment::listPayments();
        foreach ($gateways as $gateway) {
            $points = $gateway->registerEntryPoints();

            if (isset($points[$code])) {
                return $gateway->{$points[$code]}($params);
            }
        }

        return Response::make('Access Forbidden', '403');
    }

    //
    // Partials
    //

    /**
     * Loops over each payment type and ensures the editing theme has a payment form partial,
     * if the partial does not exist, it will create one.
     * @return void
     */
    public static function createPartials()
    {
        $themeManager = resolve(ThemeManager::class);
        if (!$theme = $themeManager->getActiveTheme()) {
            return;
        }

        $partials = $theme->listPartials()->pluck('baseFileName', 'baseFileName')->all();
        $paymentMethods = Payment::whereIsEnabled()->get();

        foreach ($paymentMethods as $paymentMethod) {
            $class = $paymentMethod->getGatewayClass();

            if (!$class || get_parent_class($class) != BasePaymentGateway::class) {
                continue;
            }

            $partialName = 'payregister/'.strtolower(class_basename($class));
            $partialPath = $theme->getPath().'/_partials/'.$partialName.'.'.Model::DEFAULT_EXTENSION;

            if (!File::isDirectory(dirname($partialPath))) {
                File::makeDirectory(dirname($partialPath), recursive: true);
            }

            if (!array_key_exists($partialName, $partials)) {
                File::put($partialPath, self::getFileContent($class));
            }
        }
    }

    protected static function getFileContent(string $class): string
    {
        $viewHint = str_before($class, '\\').'.'.str_before(str_after($class, '\\'), '\\');
        $viewHint .= '::_partials.'.class_basename($class);
        $viewHint .= '.payment_form';

        return view()->getFinder()->find($class::$paymentFormView ?? strtolower($viewHint));
    }
}
