<?php

namespace Igniter\PayRegister\Tests\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentLog;
use Igniter\PayRegister\Payments\PaypalExpress;
use Igniter\User\Models\Customer;
use Illuminate\Http\Client\Response;
use Mockery;

beforeEach(function() {
    $this->payment = Payment::factory()->create([
        'class_name' => PaypalExpress::class,
    ]);
    $this->paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $this->order = Mockery::mock(Order::class)->makePartial();
    $this->order->payment_method = $this->payment;
    $this->paypalExpress = new PaypalExpress($this->payment);
});

it('returns correct payment form view for paypal express', function() {
    expect(PaypalExpress::$paymentFormView)->toBe('igniter.payregister::_partials.paypalexpress.payment_form');
});

it('returns correct fields config for paypal express', function() {
    expect($this->paypalExpress->defineFieldsConfig())->toBe('igniter.payregister::/models/paypalexpress');
});

it('registers correct entry points for paypal express', function() {
    $entryPoints = $this->paypalExpress->registerEntryPoints();

    expect($entryPoints)->toBe([
        'paypal_return_url' => 'processReturnUrl',
        'paypal_cancel_url' => 'processCancelUrl',
    ]);
});

it('returns true if in sandbox mode for paypal express', function() {
    $this->payment->api_mode = 'sandbox';

    expect($this->paypalExpress->isSandboxMode())->toBeTrue();
});

it('returns false if not in sandbox mode for paypal express', function() {
    $this->payment->api_mode = 'live';

    expect($this->paypalExpress->isSandboxMode())->toBeFalse();
});

it('returns sandbox API username in sandbox mode for paypal express', function() {
    $this->payment->api_mode = 'sandbox';
    $this->payment->api_sandbox_user = 'sandbox_user';

    expect($this->paypalExpress->getApiUsername())->toBe('sandbox_user');
});

it('returns live API username in live mode for paypal express', function() {
    $this->payment->api_mode = 'live';
    $this->payment->api_user = 'live_user';

    expect($this->paypalExpress->getApiUsername())->toBe('live_user');
});

it('returns sandbox API password in sandbox mode for paypal express', function() {
    $this->payment->api_mode = 'sandbox';
    $this->payment->api_sandbox_pass = 'sandbox_pass';

    expect($this->paypalExpress->getApiPassword())->toBe('sandbox_pass');
});

it('returns live API password in live mode for paypal express', function() {
    $this->payment->api_mode = 'live';
    $this->payment->api_pass = 'live_pass';

    expect($this->paypalExpress->getApiPassword())->toBe('live_pass');
});

it('returns AUTHORIZE when api_action is authorization for paypal express', function() {
    $this->payment->api_action = 'authorization';

    expect($this->paypalExpress->getTransactionMode())->toBe('AUTHORIZE');
});

it('returns CAPTURE when api_action is not authorization for paypal express', function() {
    $this->payment->api_action = 'capture';

    expect($this->paypalExpress->getTransactionMode())->toBe('CAPTURE');
});

it('processes paypal express payment form and redirects to payer action url', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->order_total = 100;

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('ok')->andReturn(true);
    $response->shouldReceive('json')->with('links', [])->andReturn([['rel' => 'payer-action', 'href' => 'http://payer.action.url']]);
    $paypalExpress = Mockery::mock(PaypalExpress::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $paypalExpress->shouldReceive('getTransactionMode')->andReturn('AUTHORIZE')->once();
    $paypalExpress->shouldReceive('createClient->createOrder')->andReturn($response);

    $result = $paypalExpress->processPaymentForm([], $this->payment, $this->order);

    expect($result->getTargetUrl())->toBe('http://payer.action.url');
});

it('throws exception if paypal express payment creation fails', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')
        ->with('Payment error -> Payment error', 0, Mockery::any(), Mockery::any())
        ->once();

    $paypalExpress = Mockery::mock(PaypalExpress::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $paypalExpress->shouldReceive('validateApplicableFee')->once();
    $paypalExpress->shouldReceive('getTransactionMode')->andReturn('AUTHORIZE')->once();
    $paypalExpress->shouldReceive('createClient->createOrder')->andThrow(new Exception('Payment error'));

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later.');

    $paypalExpress->processPaymentForm([], $this->payment, $this->order);
});

it('processes paypal express return url and updates order status', function(string $transactionMode) {
    request()->merge(['token' => 'test_token']);

    $this->payment->applyGatewayClass();
    $this->order->hash = 'order_hash';
    $this->order->order_id = 1;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->order->shouldReceive('updateOrderStatus')->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();
    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('json')->withNoArgs()->andReturn([]);
    if ($transactionMode === 'CAPTURE') {
        $response->shouldReceive('json')->with('purchase_units.0.payments.captures.0.status')->andReturn('COMPLETED');
    } else {
        $response->shouldReceive('json')->with('purchase_units.0.payments.authorizations.0.status')->andReturn('CREATED');
    }

    $paypalExpress = Mockery::mock(PaypalExpress::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $paypalExpress->shouldReceive('createClient->getOrder')->andReturn(['status' => 'APPROVED', 'intent' => $transactionMode]);
    $paypalExpress->shouldReceive('createClient->captureOrder')->andReturn($response);
    $paypalExpress->shouldReceive('createClient->authorizeOrder')->andReturn($response);
    $paypalExpress->shouldReceive('createOrderModel->whereHash->first')->andReturn($this->order);

    $result = $paypalExpress->processReturnUrl(['order_hash']);

    expect($result->getTargetUrl())->toContain('http://localhost/checkout');
})->with([
    ['CAPTURE'],
    ['AUTHORIZE'],
]);

it('throws exception if no order found in paypal express return url', function() {
    request()->merge([
        'redirect' => 'http://redirect.url',
        'cancel' => 'http://cancel.url',
    ]);

    $response = $this->paypalExpress->processReturnUrl(['invalid_hash']);

    expect($response->getTargetUrl())->toContain('http://cancel.url')
        ->and(flash()->messages()->first())->message->not->toBeNull()->level->toBe('warning');
});

it('processes paypal express cancel url and logs payment attempt', function() {
    $this->payment->applyGatewayClass();
    $this->order->hash = 'order_hash';
    $this->order->shouldReceive('logPaymentAttempt')->once();

    $paypalExpress = Mockery::mock(PaypalExpress::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $paypalExpress->shouldReceive('createOrderModel->whereHash->first')->andReturn($this->order);

    $result = $paypalExpress->processCancelUrl(['order_hash']);

    expect($result->getTargetUrl())->toContain('http://localhost/checkout');
});

it('throws exception if no order found in paypal express cancel url', function() {
    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No order found');

    $this->paypalExpress->processCancelUrl(['invalid_hash']);
});

it('processes paypal express refund form and logs refund attempt', function() {
    $this->paymentLog->refunded_at = null;
    $this->paymentLog->response = ['purchase_units' => [['payments' => ['captures' => [['status' => 'COMPLETED', 'id' => 'payment_id']]]]]];
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->paymentLog->shouldReceive('markAsRefundProcessed')->once();

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('json')->with('id')->andReturn('refund_id');
    $response->shouldReceive('json')->withNoArgs()->andReturn([]);
    $paypalExpress = Mockery::mock(PaypalExpress::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $paypalExpress->shouldReceive('createClient->refundPayment')->andReturn($response);

    $paypalExpress->processRefundForm(['refund_type' => 'full'], $this->order, $this->paymentLog);
});

it('throws exception if no paypal express charge to refund', function() {
    $this->paymentLog->refunded_at = null;
    $this->paymentLog->response = ['purchase_units' => [['payments' => ['captures' => [['status' => 'not_completed']]]]]];

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No charge to refund');

    $this->paypalExpress->processRefundForm(['refund_type' => 'full'], $this->order, $this->paymentLog);
});
