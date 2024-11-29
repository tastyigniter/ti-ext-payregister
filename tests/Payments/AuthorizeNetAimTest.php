<?php

namespace Igniter\PayRegister\Tests\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentLog;
use Igniter\PayRegister\Payments\AuthorizeNetAim;
use Illuminate\Support\Facades\Event;
use Mockery;
use net\authorize\api\contract\v1\CreateTransactionRequest;
use net\authorize\api\contract\v1\MerchantAuthenticationType;
use net\authorize\api\contract\v1\TransactionResponseType;
use net\authorize\api\contract\v1\TransactionResponseType\MessagesAType\MessageAType;

beforeEach(function() {
    $this->payment = Payment::factory()->create([
        'class_name' => AuthorizeNetAim::class,
    ]);
    $this->authorizeNetAim = new AuthorizeNetAim($this->payment);
});

it('returns correct payment form view for authorizenet', function() {
    expect(AuthorizeNetAim::$paymentFormView)->toBe('igniter.payregister::_partials.authorizenetaim.payment_form');
});

it('returns correct fields config for authorizenet', function() {
    expect($this->authorizeNetAim->defineFieldsConfig())->toBe('igniter.payregister::/models/authorizenetaim');
});

it('returns correct endpoint for authorizenet test mode', function() {
    $this->payment->transaction_mode = 'test';

    $result = $this->authorizeNetAim->getEndPoint();

    expect($result)->toBe('https://jstest.authorize.net');
});

it('returns correct endpoint for authorizenet live mode', function() {
    $this->payment->transaction_mode = 'live';

    $result = $this->authorizeNetAim->getEndPoint();

    expect($result)->toBe('https://js.authorize.net');
});

it('returns correct authorizenet model value', function($attribute, $methodName, $value, $returnValue) {
    $this->payment->$attribute = $value;

    expect($this->authorizeNetAim->$methodName())->toBe($returnValue);
})->with([
    ['client_key', 'getClientKey', 'client123', 'client123'],
    ['transaction_key', 'getTransactionKey', 'key123', 'key123'],
    ['api_login_id', 'getApiLoginID', 'login123', 'login123'],
    ['transaction_mode', 'isTestMode', 'live', false],
    ['transaction_mode', 'isTestMode', 'test', true],
    ['transaction_type', 'shouldAuthorizePayment', 'auth_only', true],
    ['transaction_type', 'shouldAuthorizePayment', 'auth_capture', false],
]);

it('adds JavaScript file to the controller', function() {
    $controller = Mockery::mock(MainController::class);

    $controller
        ->shouldReceive('addJs')
        ->with('igniter.payregister::/js/authorizenetaim.js', 'authorizenetaim-js')
        ->once();

    $this->authorizeNetAim->beforeRenderPaymentForm($this->authorizeNetAim, $controller);
});

it('processes authorizenet payment form and logs successful payment', function() {
    $messageAType = (new MessageAType())->setCode('1')->setDescription('Success');
    $response = Mockery::mock(TransactionResponseType::class);
    $response->shouldReceive('getResponseCode')->andReturn('1')->twice();
    $response->shouldReceive('getMessages')->andReturn([$messageAType])->twice();
    $response->shouldReceive('getTransId')->andReturn('12345')->once();
    $response->shouldReceive('getAccountNumber')->andReturn('****1111')->once();
    $response->shouldReceive('getAccountType')->andReturn('Visa')->once();
    $response->shouldReceive('getAuthCode')->andReturn('auth123')->once();

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $authorizeNetAim->shouldReceive('createAcceptPayment')->andReturn($response)->once();
    $authorizeNetAim->shouldReceive('shouldAuthorizePayment')->andReturnFalse()->once();

    $order = Mockery::mock(Order::class)->makePartial();
    $order->payment_method = $this->payment;
    $order->order_total = 100;
    $order->shouldReceive('logPaymentAttempt')->with('Payment successful', 1, Mockery::any(), Mockery::any(), true)->once();
    $order->shouldReceive('updateOrderStatus')->once();
    $order->shouldReceive('markAsPaymentProcessed')->once();
    $data = ['authorizenetaim_DataDescriptor' => 'descriptor', 'authorizenetaim_DataValue' => 'value'];

    $this->payment->applyGatewayClass();

    $authorizeNetAim->processPaymentForm($data, $this->payment, $order);
});

it('throws exception if authorizenet payment form processing fails', function() {
    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('logPaymentAttempt')->with('Payment error -> Payment error', 0, Mockery::any())->once();
    $order->payment_method = $this->payment;
    $order->order_total = 100;
    $data = ['authorizenetaim_DataDescriptor' => 'descriptor', 'authorizenetaim_DataValue' => 'value'];

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $authorizeNetAim->shouldReceive('createAcceptPayment')->andThrow(new Exception('Payment error'))->once();

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later.');

    $this->payment->applyGatewayClass();

    $authorizeNetAim->processPaymentForm($data, $this->payment, $order);
});

it('processes authorizenet payment form and logs failed payment', function() {
    $messageAType = (new MessageAType())->setCode('2')->setDescription('Declined');
    $response = Mockery::mock(TransactionResponseType::class);
    $response->shouldReceive('getResponseCode')->andReturn('2')->twice();
    $response->shouldReceive('getMessages')->andReturn([$messageAType])->atMost(5);
    $response->shouldReceive('getTransId')->andReturn('12345')->once();
    $response->shouldReceive('getAccountNumber')->andReturn('****1111')->once();
    $response->shouldReceive('getAccountType')->andReturn('Visa')->once();
    $response->shouldReceive('getAuthCode')->andReturn('auth123')->once();

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $authorizeNetAim->shouldReceive('createAcceptPayment')->andReturn($response)->once();

    $order = Mockery::mock(Order::class)->makePartial();
    $order->order_id = 123;
    $order->payment_method = $this->payment;
    $order->order_total = 100;
    $order->shouldReceive('logPaymentAttempt')->with('Payment unsuccessful -> Declined', 0, Mockery::any(), Mockery::any())->once();
    $order->shouldNotReceive('updateOrderStatus');
    $order->shouldNotReceive('markAsPaymentProcessed');
    $data = ['authorizenetaim_DataDescriptor' => 'descriptor', 'authorizenetaim_DataValue' => 'value'];

    $this->payment->applyGatewayClass();

    $authorizeNetAim->processPaymentForm($data, $this->payment, $order);
});

it('processes authorizenet full refund successfully', function() {
    $messageAType = (new MessageAType())->setCode('2')->setDescription('Declined');
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->shouldReceive('markAsRefundProcessed')->once();
    $paymentLog->refunded_at = null;
    $paymentLog->response = ['status' => '1', 'id' => '12345', 'card_holder' => '****1111'];

    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('logPaymentAttempt');
    $order->order_total = 100;

    $response = Mockery::mock(TransactionResponseType::class);
    $response->shouldReceive('getResponseCode')->andReturn('1')->once();
    $response->shouldReceive('getTransId')->andReturn('54321')->once();
    $response->shouldReceive('getMessages')->andReturn([$messageAType])->twice();
    $response->shouldReceive('getAccountNumber')->andReturn('****1111')->once();
    $response->shouldReceive('getAccountType')->andReturn('Visa')->once();
    $response->shouldReceive('getAuthCode')->andReturn('auth123')->once();

    $data = ['refund_type' => 'full'];

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $authorizeNetAim->shouldReceive('createRefundPayment')->andReturn($response)->once();

    $authorizeNetAim->processRefundForm($data, $order, $paymentLog);
});

it('authorizenet: throws exception if refund amount exceeds order total', function() {
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->refunded_at = null;
    $paymentLog->response = ['status' => '1', 'id' => '12345', 'card_holder' => '****1111'];

    $order = Mockery::mock(Order::class)->makePartial();
    $order->order_total = 100;

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $data = ['refund_type' => 'partial', 'refund_amount' => 150];

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Refund amount should be be less than or equal to the order total');

    $authorizeNetAim->processRefundForm($data, $order, $paymentLog);
});

it('authorizenet: captures authorized payment successfully', function() {
    Event::fake();

    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->response = ['id' => '12345'];

    $order = Mockery::mock(Order::class)->makePartial();
    $order->hash = 'order_hash';
    $order->shouldReceive('logPaymentAttempt')->once();
    $order->shouldReceive('payment_logs->firstWhere')
        ->with('is_success', true)
        ->andReturn($paymentLog)
        ->once();

    $messageAType = (new MessageAType())->setCode('1')->setDescription('Success');
    $response = Mockery::mock(TransactionResponseType::class);
    $response->shouldReceive('getResponseCode')->andReturn('1')->twice();
    $response->shouldReceive('getTransId')->andReturn('54321')->once();
    $response->shouldReceive('getMessages')->andReturn([$messageAType])->twice();
    $response->shouldReceive('getAccountNumber')->andReturn('****1111')->once();
    $response->shouldReceive('getAccountType')->andReturn('Visa')->once();
    $response->shouldReceive('getAuthCode')->andReturn('auth123')->once();

    $request = new CreateTransactionRequest;
    $request->setMerchantAuthentication(new MerchantAuthenticationType);

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $authorizeNetAim->shouldReceive('createClient->createTransactionRequest')->andReturn($request)->once();
    $authorizeNetAim->shouldReceive('createClient->createTransaction')->andReturn($response)->once();

    $authorizeNetAim->captureAuthorizedPayment($order);

    Event::assertDispatched('payregister.authorizenetaim.extendCaptureRequest');
});

it('authorizenet: throws exception if no successful transaction to capture', function() {
    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('payment_logs->firstWhere')
        ->with('is_success', true)
        ->andReturnNull()
        ->once();

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No successful transaction to capture');

    $authorizeNetAim->captureAuthorizedPayment($order);
});

it('authorizenet: cancels authorized payment successfully', function() {
    Event::fake();

    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->is_success = true;
    $paymentLog->response = ['id' => '12345'];

    $order = Mockery::mock(Order::class)->makePartial();
    $order->hash = 'order_hash';
    $order->shouldReceive('logPaymentAttempt');
    $order->shouldReceive('payment_logs->firstWhere')
        ->with('is_success', true)
        ->andReturn($paymentLog)
        ->once();

    $messageAType = (new MessageAType())->setCode('1')->setDescription('Success');
    $response = Mockery::mock(TransactionResponseType::class);
    $response->shouldReceive('getResponseCode')->andReturn('1')->twice();
    $response->shouldReceive('getTransId')->andReturn('54321')->once();
    $response->shouldReceive('getMessages')->andReturn([$messageAType])->twice();
    $response->shouldReceive('getAccountNumber')->andReturn('****1111')->once();
    $response->shouldReceive('getAccountType')->andReturn('Visa')->once();
    $response->shouldReceive('getAuthCode')->andReturn('auth123')->once();

    $request = new CreateTransactionRequest;
    $request->setMerchantAuthentication(new MerchantAuthenticationType);

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $authorizeNetAim->shouldReceive('createClient->createTransactionRequest')->andReturn($request)->once();
    $authorizeNetAim->shouldReceive('createClient->createTransaction')->andReturn($response)->once();

    $authorizeNetAim->cancelAuthorizedPayment($order);

    Event::assertDispatched('payregister.authorizenetaim.extendCancelRequest');
});

it('authorizenet: throws exception if no successful transaction to cancel', function() {
    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('payment_logs->firstWhere')
        ->with('is_success', true)
        ->andReturnNull()
        ->once();

    $authorizeNetAim = Mockery::mock(AuthorizeNetAim::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No successful transaction to capture');

    $authorizeNetAim->cancelAuthorizedPayment($order);
});

