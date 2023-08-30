<?php

namespace Igniter\PayRegister\Models;

use Igniter\Flame\Database\Model;
use Igniter\Flame\Database\Traits\Purgeable;
use Igniter\Flame\Database\Traits\Sortable;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\PaymentGateways;
use Igniter\System\Models\Concerns\Defaultable;
use Igniter\System\Models\Concerns\Switchable;

/**
 * Payment Model Class
 */
class Payment extends Model
{
    use Defaultable;
    use Purgeable;
    use Sortable;
    use Switchable;

    const SORT_ORDER = 'priority';

    /**
     * @var string The database table name
     */
    protected $table = 'payments';

    /**
     * @var string The database table primary key
     */
    protected $primaryKey = 'payment_id';

    protected $fillable = ['name', 'code', 'class_name', 'description', 'data', 'priority'];

    public $timestamps = true;

    protected $casts = [
        'data' => 'array',
        'priority' => 'integer',
    ];

    protected $purgeable = ['payment'];

    public function getDropdownOptions()
    {
        return $this->whereIsEnabled()->dropdown('name', 'code');
    }

    public static function listDropdownOptions()
    {
        $all = self::select('code', 'name', 'description')->whereIsEnabled()->get();
        $collection = $all->keyBy('code')->map(function ($model) {
            return [$model->name, $model->description];
        });

        return $collection;
    }

    public static function onboardingIsComplete()
    {
        return self::whereIsEnabled()->count() > 0;
    }

    public function listGateways()
    {
        $result = [];
        $this->gatewayManager = resolve(PaymentGateways::class);
        foreach ($this->gatewayManager->listGateways() as $code => $gateway) {
            $result[$gateway['code']] = $gateway['name'];
        }

        return $result;
    }

    //
    // Accessors & Mutators
    //

    public function setCodeAttribute($value)
    {
        $this->attributes['code'] = str_slug($value, '_');
    }

    public function purgeConfigFields(): array
    {
        $data = [];
        $attributes = $this->getAttributes();
        foreach ($this->getConfigFields() ?: [] as $name => $config) {
            if (!array_key_exists($name, $attributes)) {
                continue;
            }

            $data[$name] = $attributes[$name];
            unset($this->attributes[$name]);
        }

        return $data;
    }

    //
    // Manager
    //

    /**
     * Extends this class with the gateway class
     *
     * @param string $class Class name
     *
     * @return bool
     */
    public function applyGatewayClass($class = null)
    {
        if (is_null($class)) {
            $class = $this->class_name;
        }

        if (!class_exists($class)) {
            $class = null;
        }

        if ($class && !$this->isClassExtendedWith($class)) {
            $this->extendClassWith($class);
        }

        $this->class_name = $class;

        return !is_null($class);
    }

    public function renderPaymentForm($controller)
    {
        $this->beforeRenderPaymentForm($this, $controller);

        $paymentMethodFile = strtolower(class_basename($this->class_name));
        $partialName = 'payregister/'.$paymentMethodFile;

        return $controller->renderPartial($partialName, ['paymentMethod' => $this]);
    }

    public function getGatewayClass()
    {
        return $this->class_name;
    }

    public function getGatewayObject($class = null)
    {
        if (!$class) {
            $class = $this->class_name;
        }

        return $this->asExtension($class);
    }

    //
    // Helpers
    //

    public function defaultableKeyName(): string
    {
        return 'code';
    }

    /**
     * Return all payments
     *
     * @return array
     */
    public static function listPayments()
    {
        return self::whereIsEnabled()->get()->filter(function ($model) {
            return strlen($model->class_name) > 0;
        });
    }

    public static function syncAll()
    {
        $payments = self::pluck('code')->all();

        $gatewayManager = resolve(PaymentGateways::class);
        foreach ($gatewayManager->listGateways() as $code => $gateway) {
            if (in_array($code, $payments)) {
                continue;
            }

            $model = self::make([
                'code' => $code,
                'name' => lang($gateway['name']),
                'description' => lang($gateway['description']),
                'class_name' => $gateway['class'],
                'status' => $code === 'cod',
                'is_default' => $code === 'cod',
            ]);

            $model->applyGatewayClass();
            $model->save();
        }

        PaymentGateways::createPartials();
    }

    //
    // Payment Profiles
    //

    /**
     * Finds and returns a customer payment profile for this payment method.
     * @param \Igniter\User\Models\Customer $customer Specifies customer to find a profile for.
     * @return \Igniter\PayRegister\Models\PaymentProfile|object Returns the payment profile object or NULL if the payment profile doesn't exist.
     */
    public function findPaymentProfile($customer)
    {
        if (!$customer) {
            return null;
        }

        $query = PaymentProfile::query();

        return $query->where('customer_id', $customer->customer_id)
            ->where('payment_id', $this->payment_id)
            ->first();
    }

    /**
     * Initializes a new empty customer payment profile.
     * This method should be used by payment methods internally.
     * @param \Igniter\User\Models\Customer $customer Specifies customer to initialize a profile for.
     * @return \Igniter\PayRegister\Models\PaymentProfile Returns the payment profile object or NULL if the payment profile doesn't exist.
     */
    public function initPaymentProfile($customer)
    {
        $profile = new PaymentProfile();
        $profile->customer_id = $customer->customer_id;
        $profile->payment_id = $this->payment_id;

        return $profile;
    }

    public function paymentProfileExists($customer)
    {
        return (bool)$this->findPaymentProfile($customer);
    }

    public function deletePaymentProfile($customer)
    {
        $gatewayObj = $this->getGatewayObject();

        $profile = $this->findPaymentProfile($customer);

        if (!$profile) {
            throw new ApplicationException(lang('igniter.user::default.customers.alert_customer_payment_profile_not_found'));
        }

        $gatewayObj->deletePaymentProfile($customer, $profile);

        $profile->delete();
    }
}
