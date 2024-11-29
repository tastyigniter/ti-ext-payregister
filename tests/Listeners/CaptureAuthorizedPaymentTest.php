<?php

namespace Igniter\PayRegister\Tests\Listeners;

use Igniter\Admin\Models\StatusHistory;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Database\Model;
use Igniter\PayRegister\Listeners\CaptureAuthorizedPayment;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Tests\Payments\Fixtures\TestPayment;
use Igniter\PayRegister\Tests\Payments\Fixtures\TestPaymentWithAuthorized;
use Mockery;

beforeEach(function() {
    $this->listener = new CaptureAuthorizedPayment();
    $this->order = Order::factory()->create();
    $this->paymentMethod = Payment::factory()->create();
    $this->statusHistory = StatusHistory::factory()->create();
    $this->order->payment_method = $this->paymentMethod;
});

it('does nothing if order is not an instance of Order', function() {
    $model = Mockery::mock(Model::class);

    $result = $this->listener->handle($model, $this->statusHistory);

    expect($result)->toBeNull();
});

it('does nothing if payment method is not set', function() {
    $this->order->payment_method = null;

    $result = $this->listener->handle($this->order, $this->statusHistory);

    expect($result)->toBeNull();
});

it('does nothing if payment method does not use WithAuthorizedPayment', function() {
    $this->paymentMethod->class_name = TestPayment::class;

    $result = $this->listener->handle($this->order, $this->statusHistory);

    expect($result)->toBeNull();
});

it('does nothing if shouldCapturePayment returns false', function() {
    $paymentMethod = Mockery::mock(Payment::class)->makePartial();
    $paymentMethod->shouldReceive('getGatewayClass')->andReturn(TestPaymentWithAuthorized::class);
    $paymentMethod->shouldReceive('shouldCapturePayment')->with($this->order)->andReturn(false);
    $this->order->payment_method = $paymentMethod;

    $result = $this->listener->handle($this->order, $this->statusHistory);

    expect($result)->toBeNull();
});

it('calls captureAuthorizedPayment if shouldCapturePayment returns true', function() {
    $paymentMethod = Mockery::mock(Payment::class)->makePartial();
    $paymentMethod->shouldReceive('getGatewayClass')->andReturn(TestPaymentWithAuthorized::class);
    $paymentMethod->shouldReceive('shouldCapturePayment')->with($this->order)->andReturn(true);
    $paymentMethod->shouldReceive('captureAuthorizedPayment')->with($this->order)->once();
    $this->order->payment_method = $paymentMethod;

    $result = $this->listener->handle($this->order, $this->statusHistory);
});
