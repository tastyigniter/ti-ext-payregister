<?php

namespace Igniter\PayRegister\Tests\Models\Observers;

use Igniter\PayRegister\Models\Observers\PaymentObserver;
use Igniter\PayRegister\Models\Payment;
use Mockery;

it('applies gateway class and merges data attributes on retrieved', function() {
    $payment = Mockery::mock(Payment::class)->makePartial();
    $payment->shouldReceive('extendableGet')->with('data')->andReturn(['key' => 'value']);
    $payment->shouldReceive('applyGatewayClass')->once();
    $payment->shouldReceive('getAttributes')->andReturn(['attribute' => 'value']);
    $payment->shouldReceive('setRawAttributes')->with(['key' => 'value', 'attribute' => 'value'])->once();

    $observer = new PaymentObserver();
    $observer->retrieved($payment);
})->only();

it('purges config fields on saving if payment exists', function() {
    $payment = Mockery::mock(Payment::class)->makePartial();
    $payment->exists = true;
    $payment->shouldReceive('purgeConfigFields')->andReturn(['purged' => 'data']);
    $payment->shouldReceive('setAttribute')->with('data', ['purged' => 'data'])->once();

    $observer = new PaymentObserver();
    $observer->saving($payment);
});

it('does not purge config fields on saving if payment does not exist', function() {
    $payment = Mockery::mock(Payment::class)->makePartial();
    $payment->exists = false;
    $payment->shouldNotReceive('purgeConfigFields');

    $observer = new PaymentObserver();
    $observer->saving($payment);
});
