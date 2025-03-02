<?php

namespace Igniter\PayRegister\Models;

use Igniter\Flame\Database\Model;

class PaymentProfile extends Model
{
    public $timestamps = true;

    public $table = 'payment_profiles';

    protected $primaryKey = 'payment_profile_id';

    protected $casts = [
        'customer_id' => 'integer',
        'payment_id' => 'integer',
        'profile_data' => 'array',
        'is_primary' => 'boolean',
    ];

    public function afterSave()
    {
        if ($this->is_primary && $this->wasChanged('is_primary')) {
            $this->makePrimary();
        }
    }

    public function setProfileData($profileData)
    {
        $this->profile_data = $profileData;
        $this->save();
    }

    public function hasProfileData()
    {
        return array_has((array)$this->profile_data, ['card_id', 'customer_id']);
    }

    /**
     * Makes this model the default
     * @return void
     */
    public function makePrimary()
    {
        $this->timestamps = false;

        $this->newQuery()
            ->where('is_primary', '!=', false)
            ->where('customer_id', $this->customer_id)
            ->update(['is_primary' => false]);

        $this->newQuery()
            ->where('payment_profile_id', $this->payment_profile_id)
            ->where('customer_id', $this->customer_id)
            ->update(['is_primary' => true]);

        $this->timestamps = true;
    }

    public static function getPrimary($customer)
    {
        $profiles = self::applyCustomer($customer)->get();

        foreach ($profiles as $profile) {
            if ($profile->is_primary) {
                return $profile;
            }
        }

        return $profiles->first();
    }

    public static function customerHasProfile($customer)
    {
        return self::applyCustomer($customer)->count() > 0;
    }

    //
    // Scopes
    //

    public function scopeApplyCustomer($query, $customer)
    {
        if ($customer instanceof \Illuminate\Database\Eloquent\Model) {
            $customer = $customer->getKey();
        }

        return $query->where('customer_id', $customer);
    }
}
