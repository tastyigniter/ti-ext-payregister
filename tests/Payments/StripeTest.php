<?php

namespace Igniter\PayRegister\Tests\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentLog;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\PayRegister\Payments\PaypalExpress;
use Igniter\PayRegister\Payments\Stripe;
use Igniter\User\Models\Customer;
use Illuminate\Support\Facades\Event;
use Mockery;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Stripe\StripeObject;

beforeEach(function() {
    $this->payment = Payment::factory()->create([
        'class_name' => PaypalExpress::class,
    ]);
    $this->profile = Mockery::mock(PaymentProfile::class)->makePartial();
    $this->order = Mockery::mock(Order::class)->makePartial();
    $this->order->payment_method = $this->payment;
    $this->stripe = new Stripe($this->payment);
});

it('returns correct payment form view for stripe', function() {
    expect(Stripe::$paymentFormView)->toBe('igniter.payregister::_partials.stripe.payment_form');
});

it('returns correct fields config for stripe', function() {
    expect($this->stripe->defineFieldsConfig())->toBe('igniter.payregister::/models/stripe');
});

it('registers correct entry points for stripe', function() {
    $entryPoints = $this->stripe->registerEntryPoints();

    expect($entryPoints)->toBe([
        'stripe_webhook' => 'processWebhookUrl',
    ]);
});

it('returns hidden fields for stripe', function() {
    $hiddenFields = $this->stripe->getHiddenFields();

    expect($hiddenFields)->toHaveKeys(['stripe_payment_method', 'stripe_idempotency_key']);
});

it('returns correct stripe model value', function($attribute, $methodName, $mode, $value, $returnValue) {
    $this->payment->transaction_mode = $mode;
    $this->payment->$attribute = $value;

    expect($this->stripe->$methodName())->toBe($returnValue);
})->with([
    ['transaction_mode', 'isTestMode', 'live', 'live', false],
    ['transaction_mode', 'isTestMode', 'test', 'test', true],
    ['live_publishable_key', 'getPublishableKey', 'live', 'client123', 'client123'],
    ['test_publishable_key', 'getPublishableKey', 'test', 'client123', 'client123'],
    ['test_secret_key', 'getSecretKey', 'test', 'test_secret_key', 'test_secret_key'],
    ['live_secret_key', 'getSecretKey', 'live', 'live_secret_key', 'live_secret_key'],
    ['test_webhook_secret', 'getWebhookSecret', 'test', 'test_webhook_secret', 'test_webhook_secret'],
    ['live_webhook_secret', 'getWebhookSecret', 'live', 'live_webhook_secret', 'live_webhook_secret'],
    ['transaction_type', 'shouldAuthorizePayment', 'test', 'auth_only', true],
    ['transaction_type', 'shouldAuthorizePayment', 'live', 'sale', false],
]);

it('adds correct js files for stripe payment form', function() {
    $controller = Mockery::mock(MainController::class);
    $controller->shouldReceive('addJs')->with('https://js.stripe.com/v3/', 'stripe-js')->once();
    $controller->shouldReceive('addJs')->with('igniter.payregister::/js/process.stripe.js', 'process-stripe-js')->once();

    $this->stripe->beforeRenderPaymentForm($this->stripe, $controller);
});

it('returns true for completesPaymentOnClient for stripe', function() {
    expect($this->stripe->completesPaymentOnClient())->toBeTrue();
});

it('returns stripe js options with locale', function() {
    $order = Mockery::mock(Order::class);
    $this->payment->locale_code = 'en';

    Event::listen('payregister.stripe.extendJsOptions', function($stripePayment, $options, $order) {
        return [
            'test_key' => 'test_value',
        ];
    });

    expect($this->stripe->getStripeJsOptions($order))->toBe([
        'locale' => 'en',
        'test_key' => 'test_value',
    ]);
});

it('returns stripe options with extended options', function() {
    Event::listen('payregister.stripe.extendOptions', function($stripePayment, $options) {
        return [
            'test_key' => 'test_value',
        ];
    });

    expect($this->stripe->getStripeOptions())->toBe([
        'test_key' => 'test_value',
    ]);
});

it('creates stripe payment intent successfully', function() {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_secret_key = 'test_secret_key';
    $this->order->order_total = 100;

    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);

    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false)->once();
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('validateApplicableFee')->with($this->order, $this->payment)->once();
    $stripe->shouldReceive('updatePaymentIntentSession')->with($this->order)->andReturn(null)->once();
    $stripe->shouldReceive('getPaymentFormFields')->with($this->order)->andReturn(['amount' => 1000])->once();
    $stripe->shouldReceive('getStripeOptions')->andReturn([])->once();
    $gateway->shouldReceive('request')->andReturn(StripeObject::constructFrom(['id' => 'pi_123', 'client_secret' => 'secret']))->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway)->once();

    expect($stripe->createOrFetchIntent($this->order))->toBe('secret');
});

it('fetches & updates stripe payment intent successfully', function() {
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway = Mockery::mock(StripeClient::class);
    $gateway->paymentIntents = $paymentIntents;
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false)->once();
    $paymentIntents->shouldReceive('retrieve')
        ->with('pi_123', Mockery::any(), Mockery::any())
        ->andReturn(StripeObject::constructFrom(['id' => 'pi_123', 'status' => 'not_succeeded']))->once();
    $paymentIntents->shouldReceive('update')
        ->with('pi_123', Mockery::on(function($data) {
            return empty(array_only((array)$data, ['capture_method', 'setup_future_usage', 'customer']));
        }), Mockery::any())
        ->andReturn(StripeObject::constructFrom(['id' => 'pi_123', 'status' => 'not_succeeded', 'client_secret' => 'secret']))->once();
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('validateApplicableFee')->with($this->order, $this->payment)->once();
    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturn('pi_123')->once();
    $stripe->shouldReceive('getPaymentFormFields')->with($this->order, Mockery::any(), true)->andReturn(['amount' => 1000])->once();
    $stripe->shouldReceive('getStripeOptions')->andReturn([])->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway)->once();

    expect($stripe->createOrFetchIntent($this->order))->toBe('secret');
});

it('returns null if payment is already processed for stripe', function() {
    $order = Mockery::mock(Order::class);
    $order->shouldReceive('isPaymentProcessed')->andReturn(true);

    $result = $this->stripe->createOrFetchIntent($order);
    expect($result)->toBeNull();
});

it('logs error and returns null if exception occurs in createOrFetchIntent', function() {
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false);
    $this->order->shouldReceive('logPaymentAttempt')->with('Creating checkout session failed: Error', 0, [], []);
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('validateApplicableFee')->andThrow(new Exception('Error'));

    expect($stripe->createOrFetchIntent($this->order))->toBeNull()
        ->and(flash()->messages()->first())->message->not->toBeNull()->level->toBe('warning');
});

it('processes stripe payment form successfully', function() {
    $data = [];
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway = Mockery::mock(StripeClient::class);
    $gateway->paymentIntents = $paymentIntents;
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false);
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment successful', 1, $data, Mockery::any(), true)->once();
    $this->order->shouldReceive('updateOrderStatus')->with($this->payment->order_status, ['notify' => false])->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();
    $paymentIntents->shouldReceive('retrieve')->andReturn(StripeObject::constructFrom([
        'status' => 'succeeded',
        'payment_method' => (object)[
            'id' => 'pm_123',
            'card' => (object)[],
        ],
    ]));
    $stripe->shouldReceive('validateApplicableFee')->with($this->order, $this->payment);
    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturn('pi_123')->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $stripe->shouldReceive('forgetSession')->with('ti_payregister_stripe_intent')->once();

    expect($stripe->processPaymentForm($data, $this->payment, $this->order))->toBeNull();
});

it('throws exception if stripe payment intent id is missing in session', function() {
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $this->order->shouldReceive('logPaymentAttempt')->with('Payment error: Missing payment intent identifier in session.', 0, [], [])->once();
    $stripe->shouldReceive('validateApplicableFee')->with($this->order, $this->payment);
    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturnNull()->once();

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later.');

    $stripe->processPaymentForm([], $this->payment, $this->order);
});

it('logs error and throws exception if retrieving stripe payment intent fails', function() {
    $data = [];
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway = Mockery::mock(StripeClient::class);
    $gateway->paymentIntents = $paymentIntents;
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $this->order->shouldReceive('isPaymentProcessed')->andReturn(false);
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment error: Error', 0, $data, Mockery::any())->once();
    $paymentIntents->shouldReceive('retrieve')->andThrow(new Exception('Error'));
    $stripe->shouldReceive('validateApplicableFee')->with($this->order, $this->payment);
    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturn('pi_123')->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later.');

    $stripe->processPaymentForm($data, $this->payment, $this->order);
});

it('captures authorized stripe payment successfully', function() {
    $data = [];
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->response = ['id' => 'pi_123'];
    $gateway = Mockery::mock(StripeClient::class);
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn($paymentLog);
    $this->order->shouldReceive('payment')->andReturn($this->payment->code);
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $paymentIntents->shouldReceive('capture')->andReturn(StripeObject::constructFrom(['status' => 'succeeded']));
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment successful', 1, $data, Mockery::any())->once();

    $result = $stripe->captureAuthorizedPayment($this->order, $data);
    expect($result)->status->toBe('succeeded');
});

it('throws exception if no successful authorized stripe payment to capture', function() {
    $data = [];

    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn(null);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No successful authorized payment to capture');

    $this->stripe->captureAuthorizedPayment($this->order, $data);
});

it('throws exception if missing stripe payment intent id in successful authorized payment response', function() {
    $data = [];
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn($paymentLog);
    $paymentLog->response = [];

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Missing payment intent ID in successful authorized payment response');

    $this->stripe->captureAuthorizedPayment($this->order, $data);
});

it('logs error if exception occurs in captureAuthorizedPayment for stripe', function() {
    $data = [];
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->response = ['id' => 'pi_123'];
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn($paymentLog);
    $this->order->shouldReceive('payment')->andReturn($this->payment->code);
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $paymentIntents->shouldReceive('capture')->andThrow(new Exception('Error'));
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment capture failed: Error', 0, $data)->once();

    expect($stripe->captureAuthorizedPayment($this->order, $data))->toBeNull();
});

it('cancels authorized stripe payment successfully', function() {
    $data = [];
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->response = ['id' => 'pi_123'];
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn($paymentLog);
    $this->order->shouldReceive('payment')->andReturn($this->payment->code);
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $paymentIntents->shouldReceive('cancel')->andReturn((object)['status' => 'canceled']);
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment canceled successfully', 1, $data, Mockery::any())->once();

    expect($stripe->cancelAuthorizedPayment($this->order, $data))->status->toBe('canceled');
});

it('throws exception if no successful authorized stripe payment to cancel', function() {
    $data = [];
    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn(null);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No successful authorized payment to cancel');

    $this->stripe->cancelAuthorizedPayment($this->order, $data);
});

it('throws exception if missing stripe payment intent id in successful authorized payment response for cancel', function() {
    $data = [];
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn($paymentLog);
    $paymentLog->response = [];

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Missing payment intent ID in successful authorized payment response');

    $this->stripe->cancelAuthorizedPayment($this->order, $data);
});

it('logs error if exception occurs in cancelAuthorizedPayment for stripe', function() {
    $data = [];
    $paymentLog = Mockery::mock(PaymentLog::class)->makePartial();
    $paymentLog->response = ['id' => 'pi_123'];
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $this->order->shouldReceive('payment_logs->firstWhere')->with('is_success', true)->andReturn($paymentLog);
    $this->order->shouldReceive('payment')->andReturn($this->payment->code);
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $gateway->paymentIntents->shouldReceive('cancel')->andThrow(new Exception('Error'));
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment canceled failed: Error', 0, $data)->once();

    expect($stripe->cancelAuthorizedPayment($this->order, $data))->toBeNull();
});

it('updates stripe payment intent session successfully', function() {
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturn('pi_123')->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $paymentIntents->shouldReceive('retrieve')->andReturn(StripeObject::constructFrom(['id' => 'pi_123', 'status' => 'requires_payment_method']))->once();
    $stripe->shouldReceive('getPaymentFormFields')->with($this->order, [], true)->andReturn(['amount' => 1000]);
    $stripe->shouldReceive('getStripeOptions')->andReturn([]);
    $paymentIntents->shouldReceive('update')->andReturn(StripeObject::constructFrom(['id' => 'pi_123']))->once();

    expect($stripe->updatePaymentIntentSession($this->order))->id->toBe('pi_123');
});

it('returns stripe payment intent if status is requires_capture or succeeded', function() {
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturn('pi_123')->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $gateway->paymentIntents->shouldReceive('retrieve')->andReturn((object)['status' => 'requires_capture']);

    expect($stripe->updatePaymentIntentSession($this->order))->status->toBe('requires_capture');
});

it('logs error and returns false if exception occurs in updatePaymentIntentSession', function() {
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $stripe->shouldReceive('getSession')->with('ti_payregister_stripe_intent')->andReturn('pi_123')->once();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $gateway->paymentIntents->shouldReceive('retrieve')->andThrow(new Exception('Error'));
    $this->order->shouldReceive('logPaymentAttempt')->with('Updating checkout session failed: Error', 1, [], Mockery::any())->once();

    expect($stripe->updatePaymentIntentSession($this->order))->toBeFalse();
});

it('throws exception if stripe payment profile not found', function() {
    $this->order->customer = null;

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Payment profile not found or customer not logged in');

    $this->stripe->payFromPaymentProfile($this->order, []);
});

it('throws exception if stripe payment profile has no data', function() {
    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer->customer_id = 1;
    PaymentProfile::factory()->create([
        'customer_id' => 1,
    ]);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Payment profile not found or customer not logged in');

    $this->stripe->payFromPaymentProfile($this->order, []);
});

it('creates payment successfully from stripe payment profile', function() {
    $data = [];
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('paymentProfileExists')->andReturnTrue();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $stripe->shouldReceive('getStripeOptions')->andReturn([])->once();
    $stripe->shouldReceive('getPaymentFormFields')->with($this->order)->andReturn(['amount' => 1000])->once();
    $paymentIntents->shouldReceive('create')->andReturn(StripeObject::constructFrom(['status' => 'succeeded']))->once();
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment successful', 1, Mockery::any(), Mockery::any(), true)->once();
    $this->order->shouldReceive('updateOrderStatus')->with($this->payment->order_status, ['notify' => false])->once();
    $this->order->shouldReceive('markAsPaymentProcessed')->once();

    $stripe->payFromPaymentProfile($this->order, $data);
});

it('logs payment attempt and throws exception if stripe payment creation fails', function() {
    $stripe = Mockery::mock(Stripe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $gateway = Mockery::mock(StripeClient::class);
    $paymentIntents = Mockery::mock(PaymentIntentService::class);
    $gateway->paymentIntents = $paymentIntents;

    $this->order->customer = Mockery::mock(Customer::class)->makePartial();
    $this->order->customer_name = 'John Doe';
    $stripe->shouldReceive('getHostObject')->andReturn($this->payment);
    $stripe->shouldReceive('paymentProfileExists')->andReturnTrue();
    $stripe->shouldReceive('createGateway')->andReturn($gateway);
    $stripe->shouldReceive('getPaymentFormFields')->with($this->order)->andReturn(['amount' => 1000])->once();
    $paymentIntents->shouldReceive('create')->andThrow(new Exception('Payment error'));
    $this->order->shouldReceive('logPaymentAttempt')->with('Payment error: Payment error', 0, Mockery::any(), Mockery::any())->once();

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('Sorry, there was an error processing your payment. Please try again later');

    $stripe->payFromPaymentProfile($this->order, []);
});
