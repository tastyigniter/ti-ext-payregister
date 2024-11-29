<?php

use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentLog;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\PayRegister\Payments\Mollie;
use Igniter\User\Models\Customer;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment as MolliePayment;

beforeEach(function() {
    $this->payment = Payment::factory()->create([
        'class_name' => Mollie::class,
    ]);
    $this->paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $this->order = Mockery::mock(Order::class)->makePartial();
    $this->order->payment_method = $this->payment;
    $this->mollie = new Mollie($this->payment);
});

it('returns correct payment form view for mollie', function() {
    expect(Mollie::$paymentFormView)->toBe('igniter.payregister::_partials.mollie.payment_form');
});

it('returns correct fields config for mollie', function() {
    expect($this->mollie->defineFieldsConfig())->toBe('igniter.payregister::/models/mollie');
});

it('registers correct entry points for mollie', function() {
    $entryPoints = $this->mollie->registerEntryPoints();

    expect($entryPoints)->toBe([
        'mollie_return_url' => 'processReturnUrl',
        'mollie_notify_url' => 'processNotifyUrl',
    ]);
});

it('returns true if in mollie test mode', function() {
    $this->payment->transaction_mode = 'test';
    expect($this->mollie->isTestMode())->toBeTrue();
});

it('returns false if not in mollie test mode', function() {
    $this->payment->transaction_mode = 'live';
    expect($this->mollie->isTestMode())->toBeFalse();
});

it('returns mollie test API key in test mode', function() {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_api_key = 'test_key';
    expect($this->mollie->getApiKey())->toBe('test_key');
});

it('returns mollie live API key in live mode', function() {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_api_key = 'live_key';
    expect($this->mollie->getApiKey())->toBe('live_key');
});

it('processes mollie payment form and redirects to checkout url', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->order_total = 100;

    $paymentProfile = Mockery::mock(PaymentProfile::class)->makePartial();
    $paymentProfile->profile_data = ['card_id' => '123', 'customer_id' => '456'];

    $molliePayment = Mockery::mock(MolliePayment::class);
    $molliePayment->shouldReceive('isOpen')->andReturn(true)->once();
    $molliePayment->shouldReceive('getCheckoutUrl')->andReturn('http://checkout.url')->once();
    $paymentEndpoint = Mockery::mock(PaymentEndpoint::class);
    $paymentEndpoint->shouldReceive('create')->andReturn($molliePayment)->once();
    $mollieClient = Mockery::mock(MollieApiClient::class)->makePartial();
    $mollieClient->setApiKey('test_'.str_random(30));
    $mollieClient->payments = $paymentEndpoint;

    $mollie = Mockery::mock(Mollie::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $mollie->shouldReceive('validateApplicableFee')->once();
    $mollie->shouldReceive('updatePaymentProfile')->andReturn($paymentProfile)->once();
    $mollie->shouldReceive('createClient')->andReturn($mollieClient)->once();

    $response = $mollie->processPaymentForm([], $this->payment, $this->order);

    expect($response->getTargetUrl())->toBe('http://checkout.url');
});

it('throws exception if mollie payment creation fails', function() {
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment error -> Payment error', 0, Mockery::any(), Mockery::any())->once();

    $payment = Mockery::mock(MolliePayment::class);
    $payment->shouldReceive('isOpen')->andReturn(false)->once();
    $payment->shouldReceive('getMessage')->andReturn('Payment error')->once();
    $paymentEndpoint = Mockery::mock(PaymentEndpoint::class);
    $paymentEndpoint->shouldReceive('create')->andReturn($payment)->once();

    $mollieClient = Mockery::mock(MollieApiClient::class)->makePartial();
    $mollieClient->setApiKey('test_'.str_random(30));
    $mollieClient->payments = $paymentEndpoint;

    $mollie = Mockery::mock(Mollie::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $mollie->shouldReceive('createClient')->andReturn($mollieClient);
    $mollie->shouldReceive('validateApplicableFee')->once();

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later.');

    $mollie->processPaymentForm([], $this->payment, $this->order);
});

it('processes mollie return url and updates order status', function() {
    request()->merge([
        'redirect' => 'http://redirect.url',
        'cancel' => 'http://cancel.url',
    ]);

    $this->payment->applyGatewayClass();
    $this->order->hash = 'order_hash';
    $this->order->order_id = 1;
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment successful', 1, Mockery::any(), Mockery::any(), true)->once();
    $this->order->shouldReceive('updateOrderStatus')->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();
    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false);

    $molliePayment = Mockery::mock(MolliePayment::class);
    $molliePayment->shouldReceive('isPaid')->andReturn(true)->once();
    $molliePayment->metadata = ['order_id' => 1];
    $paymentEndpoint = Mockery::mock(PaymentEndpoint::class);
    $paymentEndpoint->shouldReceive('get')->andReturn($molliePayment)->once();

    $mollieClient = Mockery::mock(MollieApiClient::class)->makePartial();
    $mollieClient->setApiKey('test_'.str_random(30));
    $mollieClient->payments = $paymentEndpoint;

    $mollie = Mockery::mock(Mollie::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $mollie->shouldReceive('createClient')->andReturn($mollieClient);
    $mollie->shouldReceive('createOrderModel->whereHash->first')->andReturn($this->order);

    $response = $mollie->processReturnUrl(['order_hash']);

    expect($response->getTargetUrl())->toContain('http://redirect.url');
});

it('throws exception if no order found in mollie return url', function() {
    request()->merge([
        'redirect' => 'http://redirect.url',
        'cancel' => 'http://cancel.url',
    ]);

    $response = $this->mollie->processReturnUrl(['invalid_hash']);

    expect($response->getTargetUrl())->toContain('http://cancel.url')
        ->and(flash()->messages()->first())->message->toBe('No order found');
});

it('processes mollie notify url and updates order status', function() {
    request()->merge([
        'id' => 'payment_id',
    ]);

    $this->payment->applyGatewayClass();
    $this->order->hash = 'order_hash';
    $this->order->order_id = 1;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->order->shouldReceive('updateOrderStatus')->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();
    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false);

    $molliePayment = Mockery::mock(MolliePayment::class);
    $molliePayment->shouldReceive('isPaid')->andReturn(true)->once();
    $paymentEndpoint = Mockery::mock(PaymentEndpoint::class);
    $paymentEndpoint->shouldReceive('get')->andReturn($molliePayment)->once();
    $mollieClient = Mockery::mock(MollieApiClient::class)->makePartial();
    $mollieClient->setApiKey('test_'.str_random(30));
    $mollieClient->payments = $paymentEndpoint;

    $mollie = Mockery::mock(Mollie::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $mollie->shouldReceive('createClient')->andReturn($mollieClient);
    $mollie->shouldReceive('createOrderModel->whereHash->first')->andReturn($this->order);

    $response = $mollie->processNotifyUrl(['order_hash']);

    expect($response->getData())->success->toBe(true);
});

it('throws exception if no order found in mollie notify url', function() {
    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No order found');

    $mollie = Mockery::mock(Mollie::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $mollie->shouldReceive('createOrderModel->whereHash->first')->andReturnNull();

    $this->mollie->processNotifyUrl(['invalid_hash']);
});

it('processes mollie refund form and logs refund attempt', function() {
    $this->paymentLog->refunded_at = null;
    $this->paymentLog->response = ['status' => 'paid', 'id' => 'payment_id'];
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->paymentLog->shouldReceive('markAsRefundProcessed')->once();

    $molliePayment = Mockery::mock(MolliePayment::class);
    $molliePayment->shouldReceive('refund')->andReturn((object)[
        'id' => 'refund_id',
        'status' => 'refunded',
        'amount' => (object)['value' => '100.00', 'currency' => 'GBP'],
    ])->once();
    $paymentEndpoint = Mockery::mock(PaymentEndpoint::class);
    $paymentEndpoint->shouldReceive('get')->andReturn($molliePayment)->once();
    $mollieClient = Mockery::mock(MollieApiClient::class)->makePartial();
    $mollieClient->setApiKey('test_'.str_random(30));
    $mollieClient->payments = $paymentEndpoint;

    $mollie = Mockery::mock(Mollie::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $mollie->shouldReceive('createClient')->andReturn($mollieClient);

    $mollie->processRefundForm(['refund_type' => 'full'], $this->order, $this->paymentLog);
});

it('throws exception if no mollie charge to refund', function() {
    $this->paymentLog->refunded_at = null;
    $this->paymentLog->response = ['status' => 'not_paid'];

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No charge to refund');

    $this->mollie->processRefundForm(['refund_type' => 'full'], $this->order, $this->paymentLog);
});
