<?php

namespace Igniter\PayRegister\Tests\Payments;

use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentLog;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\PayRegister\Payments\PaypalExpress;
use Igniter\PayRegister\Payments\Square;
use Igniter\User\Models\Customer;
use Mockery;
use Square\Apis\RefundsApi;
use Square\Http\ApiResponse;
use Square\Models\Error;
use Square\SquareClient;

beforeEach(function() {
    $this->payment = Payment::factory()->create([
        'class_name' => PaypalExpress::class,
    ]);
    $this->paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $this->order = Mockery::mock(Order::class)->makePartial();
    $this->order->payment_method = $this->payment;
    $this->square = new Square($this->payment);
});

it('returns correct payment form view for square', function() {
    expect(Square::$paymentFormView)->toBe('igniter.payregister::_partials.square.payment_form');
});

it('returns correct fields config for square', function() {
    expect($this->square->defineFieldsConfig())->toBe('igniter.payregister::/models/square');
});

it('returns hidden fields for square', function() {
    $hiddenFields = $this->square->getHiddenFields();
    expect($hiddenFields)->toBe([
        'square_card_nonce' => '',
        'square_card_token' => '',
    ]);
});

it('returns true if in test mode for square', function() {
    $this->payment->transaction_mode = 'test';
    expect($this->square->isTestMode())->toBeTrue();
});

it('returns false if not in test mode for square', function() {
    $this->payment->transaction_mode = 'live';
    expect($this->square->isTestMode())->toBeFalse();
});

it('returns test app id in test mode for square', function() {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_app_id = 'test_app_id';
    expect($this->square->getAppId())->toBe('test_app_id');
});

it('returns live app id in live mode for square', function() {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_app_id = 'live_app_id';
    expect($this->square->getAppId())->toBe('live_app_id');
});

it('returns test access token in test mode for square', function() {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    expect($this->square->getAccessToken())->toBe('test_access_token');
});

it('returns live access token in live mode for square', function() {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_access_token = 'live_access_token';
    expect($this->square->getAccessToken())->toBe('live_access_token');
});

it('returns test location id in test mode for square', function() {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_location_id = 'test_location_id';
    expect($this->square->getLocationId())->toBe('test_location_id');
});

it('returns live location id in live mode for square', function() {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_location_id = 'live_location_id';
    expect($this->square->getLocationId())->toBe('live_location_id');
});

it('adds correct js files in test mode for square', function() {
    $this->payment->transaction_mode = 'test';

    $controller = Mockery::mock(MainController::class);
    $controller->shouldReceive('addJs')->with('https://sandbox.web.squarecdn.com/v1/square.js', 'square-js')->once();
    $controller->shouldReceive('addJs')->with('igniter.payregister::/js/process.square.js', 'process-square-js')->once();

    $this->square->beforeRenderPaymentForm($this->square, $controller);
});

it('adds correct js files in live mode for square', function() {
    $this->payment->transaction_mode = 'live';

    $controller = Mockery::mock(MainController::class);
    $controller->shouldReceive('addJs')->with('https://web.squarecdn.com/v1/square.js', 'square-js')->once();
    $controller->shouldReceive('addJs')->with('igniter.payregister::/js/process.square.js', 'process-square-js')->once();

    $this->square->beforeRenderPaymentForm($this->square, $controller);
});

it('returns true for completesPaymentOnClient for square', function() {
    expect($this->square->completesPaymentOnClient())->toBeTrue();
});

it('processes square payment form and returns success', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();

    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('isSuccess')->andReturn(true);
    $response->shouldReceive('getResult')->andReturn(['payment' => 'success']);
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('createPayment')->andReturn($response);

    $square->processPaymentForm(['square_card_nonce' => 'nonce'], $this->payment, $this->order);
});

it('processes square payment form with payment profile and returns success', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();
    $this->order->shouldReceive('updateOrderStatus')->once();
    $paymentProfile = Mockery::mock(PaymentProfile::class)->makePartial();
    $paymentProfile->profile_data = ['card_id' => '123', 'customer_id' => '456'];
    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('isSuccess')->andReturn(true);
    $response->shouldReceive('getResult')->andReturn(['payment' => 'success']);
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('createPayment')->andReturn($response);
    $square->shouldReceive('updatePaymentProfile')->andReturn($paymentProfile);

    $square->processPaymentForm([
        'create_payment_profile' => 1,
        'square_card_nonce' => 'nonce',
    ], $this->payment, $this->order);
});

it('throws exception if square payment creation fails', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment error -> Payment error', 0, Mockery::any(), Mockery::any())->once();

    $errorMock = Mockery::mock(Error::class);
    $errorMock->shouldReceive('getDetail')->andReturn('Payment error');
    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('isSuccess')->andReturn(false);
    $response->shouldReceive('getErrors')->andReturn([$errorMock]);
    $response->shouldReceive('getResult')->andReturn([]);
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('createPayment')->andReturn($response);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later');

    $square->processPaymentForm(['square_card_nonce' => 'nonce'], $this->payment, $this->order);
});

it('processes square refund form and logs refund attempt', function() {
    $this->paymentLog->refunded_at = null;
    $this->paymentLog->response = ['payment' => ['status' => 'COMPLETED', 'id' => 'payment_id']];
    $this->order->order_total = 100;
    $this->order->shouldReceive('logPaymentAttempt')->with(Mockery::type('string'), 1, Mockery::any(), Mockery::any())->once();
    $this->paymentLog->shouldReceive('markAsRefundProcessed')->once();

    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('isSuccess')->andReturn(true);
    $response->shouldReceive('getResult')->andReturn(['id' => 'refund_id']);
    $refundsApi = Mockery::mock(RefundsApi::class);
    $refundsApi->shouldReceive('refundPayment')->andReturn($response)->once();
    $squareClient = Mockery::mock(SquareClient::class);
    $squareClient->shouldReceive('getRefundsApi')->andReturn($refundsApi);
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('createClient')->andReturn($squareClient);

    $square->processRefundForm(['refund_type' => 'full'], $this->order, $this->paymentLog);
});

it('throws exception if no square charge to refund', function() {
    $this->paymentLog->refunded_at = null;
    $this->paymentLog->response = ['payment' => ['status' => 'not_completed']];

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No charge to refund');

    $this->square->processRefundForm(['refund_type' => 'full'], $this->order, $this->paymentLog);
});

it('creates payment successfully from square payment profile', function() {
    PaymentProfile::factory()->create([
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['card_id' => 'card123', 'customer_id' => 'cust123'],
    ]);
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer_name = 'John Doe';
    $this->order->customer->customer_id = 1;
    $this->order->shouldReceive('logPaymentAttempt')->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();
    $this->order->shouldReceive('updateOrderStatus')->once();

    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('isSuccess')->andReturn(true);
    $response->shouldReceive('getResult')->andReturn(['payment' => 'success']);
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('getHostObject')->andReturn($this->payment);
    $square->shouldReceive('createPayment')->andReturn($response);

    $square->payFromPaymentProfile($this->order, []);
});

it('throws exception if square payment profile not found', function() {
    $this->order->customer = null;

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Payment profile not found');

    $this->square->payFromPaymentProfile($this->order, []);
});

it('throws exception if square payment profile has no data', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer->customer_id = 1;
    PaymentProfile::factory()->create([
        'payment_id' => $this->payment->getKey(),
    ]);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Payment profile not found');

    $this->square->payFromPaymentProfile($this->order, []);
});

it('creates new square payment profile if none exists', function() {
    $data = ['square_card_nonce' => 'nonce'];
    $this->order->customer = $customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer->customer_id = 1;

    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('getCustomer->getId')->andReturn('cust123');
    $response->shouldReceive('getCustomer->getReferenceId')->andReturn('ref123');
    $response->shouldReceive('getCard->getId')->andReturn('card123');
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('getHostObject')->andReturn($this->payment);
    $square->shouldReceive('createOrFetchCustomer')->andReturn($response);
    $square->shouldReceive('createOrFetchCard')->andReturn($response);
    $square->shouldReceive('updatePaymentProfileData')->with(Mockery::any(), [
        'customer_id' => 'cust123',
        'card_id' => 'card123',
    ], Mockery::any())->once();

    $square->updatePaymentProfile($customer, $data);
});

it('updates existing square payment profile', function() {
    $data = ['square_card_nonce' => 'nonce'];
    $this->order->customer = $customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer->customer_id = 1;
    $profile = PaymentProfile::factory()->create([
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['card_id' => 'card123', 'customer_id' => 'cust123'],
    ]);

    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('getCustomer->getId')->andReturn('cust123');
    $response->shouldReceive('getCustomer->getReferenceId')->andReturn('ref123');
    $response->shouldReceive('getCard->getId')->andReturn('card123');
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('getHostObject')->andReturn($this->payment);
    $square->shouldReceive('createOrFetchCustomer')->andReturn($response);
    $square->shouldReceive('createOrFetchCard')->andReturn($response);
    $square->shouldReceive('updatePaymentProfileData')->with(Mockery::any(), [
        'customer_id' => 'cust123',
        'card_id' => 'card123',
    ], Mockery::any())->once();

    $result = $square->updatePaymentProfile($customer, $data);
    expect($result->getKey())->toBe($profile->getKey());
});

it('throws exception if createOrFetchCustomer fails for square', function() {
    $data = ['square_card_nonce' => 'nonce'];
    $this->order->customer = $customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer->customer_id = 1;
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('getHostObject')->andReturn($this->payment);
    $square->shouldReceive('createOrFetchCustomer')->andThrow(new ApplicationException('Customer creation failed'));

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Customer creation failed');

    $square->updatePaymentProfile($customer, $data);
});

it('throws exception if createOrFetchCard fails for square', function() {
    $data = ['square_card_nonce' => 'nonce'];
    $this->order->customer = $customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer->customer_id = 1;

    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('getCustomer->getId')->andReturn('cust123');
    $response->shouldReceive('getCustomer->getReferenceId')->andReturn('ref123');
    $square = Mockery::mock(Square::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $square->shouldReceive('getHostObject')->andReturn($this->payment);
    $square->shouldReceive('createOrFetchCustomer')->andReturn($response);

    $square->shouldReceive('createOrFetchCard')->andThrow(new ApplicationException('Card creation failed'));

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Card creation failed');

    $square->updatePaymentProfile($customer, $data);
});
