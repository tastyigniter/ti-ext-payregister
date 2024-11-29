<?php

namespace Igniter\PayRegister\Tests\Listeners;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Listeners\UpdatePaymentIntentSessionOnCheckout;
use Igniter\PayRegister\Models\Payment;
use Mockery;

beforeEach(function() {
    $this->listener = new UpdatePaymentIntentSessionOnCheckout();
    $this->order = Order::factory()->create();
    $this->paymentMethod = Mockery::mock(Payment::class)->makePartial();
    $this->order->payment_method = $this->paymentMethod;
});

it('does nothing if payment method is not set', function() {
    $this->order->payment_method = null;

    $result = $this->listener->handle($this->order);

    expect($result)->toBeNull();
});

it('does nothing if payment method is not an instance of Payment', function() {
    $result = $this->listener->handle($this->order);

    expect($result)->toBeNull();
});

it('does nothing if updatePaymentIntentSession method does not exist', function() {
    $this->paymentMethod->shouldReceive('methodExists')->with('updatePaymentIntentSession')->andReturn(false);

    $result = $this->listener->handle($this->order);

    expect($result)->toBeNull();
});

it('calls updatePaymentIntentSession if method exists', function() {
    $this->paymentMethod->shouldReceive('methodExists')->with('updatePaymentIntentSession')->andReturn(true);
    $this->paymentMethod->shouldReceive('updatePaymentIntentSession')->with($this->order)->once();

    $this->listener->handle($this->order);
});
