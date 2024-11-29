<?php

namespace Igniter\PayRegister\Tests\Concerns;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithPaymentProfile;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\User\Models\Customer;
use Mockery;

beforeEach(function() {
    $this->customer = Customer::factory()->create();
    $this->order = Mockery::mock(Order::class);
    $this->profile = Mockery::mock(PaymentProfile::class);
    $payment = Mockery::mock(Payment::class);
    $this->trait = new class($payment) extends BasePaymentGateway
    {
        use WithPaymentProfile;

        public function defineFieldsConfig()
        {
            return __DIR__.'/../_fixtures/fields';
        }
    };
});

it('throws exception if updatePaymentProfile is not implemented', function() {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Method updatePaymentProfile must be implemented on your custom payment class.');

    $this->trait->updatePaymentProfile($this->customer, []);
});

it('throws exception if deletePaymentProfile is not implemented', function() {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Method deletePaymentProfile must be implemented on your custom payment class.');

    $this->trait->deletePaymentProfile($this->customer, $this->profile);
});

it('throws exception if payFromPaymentProfile is not implemented', function() {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Method payFromPaymentProfile must be implemented on your custom payment class.');

    $this->trait->payFromPaymentProfile($this->order, []);
});
