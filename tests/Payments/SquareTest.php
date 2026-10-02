<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Tests\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentLog;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\PayRegister\Payments\Square;
use Igniter\User\Models\Customer;
use Mockery;
use ReflectionMethod;
use Square\Cards\CardsClient;
use Square\Customers\CustomersClient;
use Square\Exceptions\SquareApiException;
use Square\Payments\PaymentsClient;
use Square\Refunds\RefundsClient;
use Square\SquareClient;
use Square\Types\Card;
use Square\Types\CreateCardResponse;
use Square\Types\CreateCustomerResponse;
use Square\Types\CreatePaymentResponse;
use Square\Types\GetCardResponse;
use Square\Types\GetCustomerResponse;
use Square\Types\PaymentRefund;
use Square\Types\RefundPaymentResponse;

beforeEach(function(): void {
    $this->payment = Payment::factory()->create([
        'class_name' => Square::class,
    ]);
    $this->square = new Square($this->payment);
});

function setupSquareClient(): SquareClient
{
    $client = Mockery::mock(SquareClient::class);
    $square = Mockery::mock(Square::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $square->__construct(test()->payment);
    $square->shouldReceive('createClient')->andReturn($client);
    test()->square = $square;

    return $client;
}

function squareApiException(string $detail, bool $jsonBody = false): SquareApiException
{
    $body = [
        'errors' => [[
            'category' => 'INVALID_REQUEST_ERROR',
            'code' => 'BAD_REQUEST',
            'detail' => $detail,
        ]],
    ];

    return new SquareApiException('API request failed', 400, $jsonBody ? json_encode($body) : $body);
}

function fakePaymentResponse(): CreatePaymentResponse
{
    $response = Mockery::mock(CreatePaymentResponse::class);
    $response->shouldReceive('toJson')->andReturn(json_encode([
        'payment' => ['id' => 'payment_id', 'status' => 'COMPLETED'],
    ]));

    return $response;
}

function fakeSquareCustomer(): \Square\Types\Customer
{
    $customer = Mockery::mock(\Square\Types\Customer::class);
    $customer->shouldReceive('getId')->andReturn('cust123');
    $customer->shouldReceive('getReferenceId')->andReturn('ref123');

    return $customer;
}

function fakeSquareCard(): Card
{
    $card = Mockery::mock(Card::class);
    $card->shouldReceive('getId')->andReturn('card123');
    $card->shouldReceive('getCardBrand')->andReturn('VISA');
    $card->shouldReceive('getLast4')->andReturn('4242');

    return $card;
}

function setupSuccessfulPayment(SquareClient $client): void
{
    $payments = Mockery::mock(PaymentsClient::class);
    $client->payments = $payments;
    $payments->shouldReceive('create')->andReturn(fakePaymentResponse());
}

it('returns correct payment form view for square', function(): void {
    expect(Square::$paymentFormView)->toBe('igniter.payregister::_partials.square.payment_form');
});

it('returns correct fields config for square', function(): void {
    expect($this->square->defineFieldsConfig())->toBe('igniter.payregister::/models/square');
});

it('returns hidden fields for square', function(): void {
    $hiddenFields = $this->square->getHiddenFields();
    expect($hiddenFields)->toBe([
        'square_card_nonce' => '',
        'square_card_token' => '',
    ]);
});

it('returns true if in test mode for square', function(): void {
    $this->payment->transaction_mode = 'test';
    expect($this->square->isTestMode())->toBeTrue();
});

it('returns false if not in test mode for square', function(): void {
    $this->payment->transaction_mode = 'live';
    expect($this->square->isTestMode())->toBeFalse();
});

it('returns test app id in test mode for square', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_app_id = 'test_app_id';
    expect($this->square->getAppId())->toBe('test_app_id');
});

it('returns live app id in live mode for square', function(): void {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_app_id = 'live_app_id';
    expect($this->square->getAppId())->toBe('live_app_id');
});

it('returns test access token in test mode for square', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    expect($this->square->getAccessToken())->toBe('test_access_token');
});

it('returns live access token in live mode for square', function(): void {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_access_token = 'live_access_token';
    expect($this->square->getAccessToken())->toBe('live_access_token');
});

it('returns test location id in test mode for square', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_location_id = 'test_location_id';
    expect($this->square->getLocationId())->toBe('test_location_id');
});

it('returns live location id in live mode for square', function(): void {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_location_id = 'live_location_id';
    expect($this->square->getLocationId())->toBe('live_location_id');
});

it('adds correct js files in test mode for square', function(): void {
    $this->payment->transaction_mode = 'test';

    $controller = Mockery::mock(MainController::class);
    $controller->shouldReceive('addJs')->with('https://sandbox.web.squarecdn.com/v1/square.js', 'square-js')->once();
    $controller->shouldReceive('addJs')->with('igniter.payregister::/js/process.square.js', 'process-square-js')->once();

    $this->square->beforeRenderPaymentForm($this->square, $controller);
});

it('adds correct js files in live mode for square', function(): void {
    $this->payment->transaction_mode = 'live';

    $controller = Mockery::mock(MainController::class);
    $controller->shouldReceive('addJs')->with('https://web.squarecdn.com/v1/square.js', 'square-js')->once();
    $controller->shouldReceive('addJs')->with('igniter.payregister::/js/process.square.js', 'process-square-js')->once();

    $this->square->beforeRenderPaymentForm($this->square, $controller);
});

it('returns true for completesPaymentOnClient for square', function(): void {
    expect($this->square->completesPaymentOnClient())->toBeTrue();
});

it('processes square payment form and returns success', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    $order->totals()->create(['code' => 'tip', 'title' => 'Tip', 'value' => 100]);

    $client = setupSquareClient();
    setupSuccessfulPayment($client);

    $this->square->processPaymentForm([
        'square_card_nonce' => 'nonce',
        'square_card_token' => 'token',
    ], $this->payment, $order);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment successful',
        'is_success' => 1,
        'is_refundable' => 1,
    ]);
});

it('processes square payment form with new payment profile and returns success', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    $client = setupSquareClient();

    $customers = Mockery::mock(CustomersClient::class);
    $client->customers = $customers;
    $createCustomerResponse = Mockery::mock(CreateCustomerResponse::class);
    $customers->shouldReceive('create')->andReturn($createCustomerResponse);
    $createCustomerResponse->shouldReceive('getCustomer')->andReturn(fakeSquareCustomer());

    $cards = Mockery::mock(CardsClient::class);
    $client->cards = $cards;
    $createCardResponse = Mockery::mock(CreateCardResponse::class);
    $cards->shouldReceive('create')->andReturn($createCardResponse);
    $createCardResponse->shouldReceive('getCard')->andReturn(fakeSquareCard());

    setupSuccessfulPayment($client);

    $this->square->processPaymentForm([
        'create_payment_profile' => 1,
        'square_card_nonce' => 'nonce',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ], $this->payment, $order);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment successful',
        'is_success' => 1,
        'is_refundable' => 1,
    ]);
});

it('processes square payment form with existing payment profile and returns success', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    PaymentProfile::factory()->create([
        'customer_id' => $order->customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['card_id' => 'card123', 'customer_id' => 'cust123'],
    ]);
    $client = setupSquareClient();

    $customers = Mockery::mock(CustomersClient::class);
    $client->customers = $customers;
    $retrieveCustomerResponse = Mockery::mock(GetCustomerResponse::class);
    $customers->shouldReceive('get')->andReturn($retrieveCustomerResponse);
    $retrieveCustomerResponse->shouldReceive('getCustomer')->andReturn(fakeSquareCustomer());

    $cards = Mockery::mock(CardsClient::class);
    $client->cards = $cards;
    $retrieveCardResponse = Mockery::mock(GetCardResponse::class);
    $cards->shouldReceive('get')->andReturn($retrieveCardResponse);
    $retrieveCardResponse->shouldReceive('getCard')->andReturn(fakeSquareCard());

    setupSuccessfulPayment($client);

    $this->square->processPaymentForm([
        'create_payment_profile' => 1,
        'square_card_nonce' => 'nonce',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ], $this->payment, $order);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment successful',
        'is_success' => 1,
        'is_refundable' => 1,
    ]);
});

it('throws exception if payment request fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);

    $client = setupSquareClient();
    $payments = Mockery::mock(PaymentsClient::class);
    $client->payments = $payments;
    $payments->shouldReceive('create')->andThrow(new Exception('Payment error'));

    expect(fn() => $this->square->processPaymentForm(['square_card_nonce' => 'nonce'], $this->payment, $order))
        ->toThrow(ApplicationException::class, 'Sorry, there was an error processing your payment. Please try again later');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment error -> Payment error',
        'is_success' => 0,
    ]);
});

it('throws exception if payment response fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);

    $client = setupSquareClient();
    $payments = Mockery::mock(PaymentsClient::class);
    $client->payments = $payments;
    $payments->shouldReceive('create')->andThrow(squareApiException('Payment error'));

    expect(fn() => $this->square->processPaymentForm(['square_card_nonce' => 'nonce'], $this->payment, $order))
        ->toThrow(ApplicationException::class, 'Sorry, there was an error processing your payment. Please try again later');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment error -> Payment error',
        'is_success' => 0,
    ]);
});

it('throws exception when createOrFetchCustomer fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    PaymentProfile::factory()->create([
        'customer_id' => $order->customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['customer_id' => 'cust123', 'card_id' => 'card123'],
    ]);
    $client = setupSquareClient();
    $customers = Mockery::mock(CustomersClient::class);
    $client->customers = $customers;
    $customers->shouldReceive('get')->andThrow(squareApiException('Customer missing'));
    $customers->shouldReceive('create')->andThrow(squareApiException('Customer creation failed'));

    expect(fn() => $this->square->processPaymentForm([
        'create_payment_profile' => 1,
        'square_card_nonce' => 'nonce',
    ], $this->payment, $order))
        ->toThrow(ApplicationException::class, 'Square Customer Create Error: Customer creation failed');
});

it('throws exception when createOrFetchCard fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    PaymentProfile::factory()->create([
        'customer_id' => $order->customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['customer_id' => 'cust123', 'card_id' => 'card123'],
    ]);
    $client = setupSquareClient();
    $customers = Mockery::mock(CustomersClient::class);
    $client->customers = $customers;
    $retrieveCustomerResponse = Mockery::mock(GetCustomerResponse::class);
    $customers->shouldReceive('get')->andReturn($retrieveCustomerResponse);
    $retrieveCustomerResponse->shouldReceive('getCustomer')->andReturn(fakeSquareCustomer());

    $cards = Mockery::mock(CardsClient::class);
    $client->cards = $cards;
    $cards->shouldReceive('get')->andThrow(squareApiException('Card missing'));
    $cards->shouldReceive('create')->andThrow(squareApiException('Card creation failed'));

    expect(fn() => $this->square->processPaymentForm([
        'create_payment_profile' => 1,
        'square_card_nonce' => 'nonce',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ], $this->payment, $order))
        ->toThrow(ApplicationException::class, 'Square Create Payment Card Error: Card creation failed');
});

it('returns true when payment profiles are supported', function(): void {
    $result = $this->square->supportsPaymentProfiles();

    expect($result)->toBeTrue();
});

it('processes square refund form and logs refund attempt', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()->for($this->payment, 'payment_method')->create(['order_total' => 100]);
    $paymentLog = PaymentLog::factory()->create([
        'order_id' => $order->order_id,
        'response' => ['payment' => ['status' => 'COMPLETED', 'id' => 'payment_id']],
    ]);

    $client = setupSquareClient();
    $refund = Mockery::mock(PaymentRefund::class);
    $refund->shouldReceive('getId')->andReturn('refund_id');
    $response = Mockery::mock(RefundPaymentResponse::class);
    $response->shouldReceive('getRefund')->andReturn($refund);
    $response->shouldReceive('toJson')->andReturn(json_encode(['refund' => ['id' => 'refund_id']]));
    $refunds = Mockery::mock(RefundsClient::class);
    $refunds->shouldReceive('refundPayment')->andReturn($response)->once();
    $client->refunds = $refunds;

    $this->square->processRefundForm(['refund_type' => 'full'], $order, $paymentLog);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment payment_id refunded successfully -> (full: refund_id)',
        'is_success' => 1,
    ]);
});

it('throws exception when charge is already refunded', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()->for($this->payment, 'payment_method')->create(['order_total' => 100]);
    $paymentLog = PaymentLog::factory()->create([
        'order_id' => $order->order_id,
        'response' => ['payment' => ['status' => 'not_completed']],
        'refunded_at' => now(),
    ]);

    expect(fn() => $this->square->processRefundForm(['refund_type' => 'full'], $order, $paymentLog))
        ->toThrow(ApplicationException::class, 'Nothing to refund, payment already refunded');
});

it('throws exception when no square charge to refund', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()->for($this->payment, 'payment_method')->create(['order_total' => 100]);
    $paymentLog = PaymentLog::factory()->create([
        'order_id' => $order->order_id,
        'response' => ['payment' => ['status' => 'not_completed']],
    ]);

    expect(fn() => $this->square->processRefundForm(['refund_type' => 'full'], $order, $paymentLog))
        ->toThrow(ApplicationException::class, 'No charge to refund');
});

it('throws exception when refund response fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()->for($this->payment, 'payment_method')->create(['order_total' => 100]);
    $paymentLog = PaymentLog::factory()->create([
        'order_id' => $order->order_id,
        'response' => ['payment' => ['status' => 'COMPLETED', 'id' => 'payment_id']],
    ]);

    $client = setupSquareClient();
    $refunds = Mockery::mock(RefundsClient::class);
    $refunds->shouldReceive('refundPayment')->andThrow(squareApiException('Refund failed'))->once();
    $client->refunds = $refunds;

    $this->square->bindEvent('square.extendRefundFields', fn($fields, $order, $data): array => [
        'extra_field' => 'extra_value',
    ]);

    $this->square->processRefundForm(['refund_type' => 'full'], $order, $paymentLog);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Refund failed -> Refund failed',
        'is_success' => 0,
    ]);
});

it('logs a refund failure when the refund request throws', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()->for($this->payment, 'payment_method')->create(['order_total' => 100]);
    $paymentLog = PaymentLog::factory()->create([
        'order_id' => $order->order_id,
        'response' => ['payment' => ['status' => 'COMPLETED', 'id' => 'payment_id']],
    ]);

    $client = setupSquareClient();
    $refunds = Mockery::mock(RefundsClient::class);
    $refunds->shouldReceive('refundPayment')->andThrow(new Exception('Network down'))->once();
    $client->refunds = $refunds;

    $this->square->processRefundForm(['refund_type' => 'full'], $order, $paymentLog);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Refund failed -> Network down',
        'is_success' => 0,
    ]);
});

it('creates payment successfully from square payment profile', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    PaymentProfile::factory()->create([
        'customer_id' => $order->customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['card_id' => 'card123', 'customer_id' => 'cust123'],
    ]);

    $client = setupSquareClient();
    setupSuccessfulPayment($client);

    $this->square->payFromPaymentProfile($order, []);

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment successful',
        'is_success' => 1,
        'is_refundable' => 1,
    ]);
});

it('throws exception when no square payment profile is found', function(): void {
    $order = Order::factory()
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);

    expect(fn() => $this->square->payFromPaymentProfile($order, []))
        ->toThrow(ApplicationException::class, 'Payment profile not found');
});

it('throws exception when payment request fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    PaymentProfile::factory()->create([
        'customer_id' => $order->customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['card_id' => 'card123', 'customer_id' => 'cust123'],
    ]);

    $client = setupSquareClient();
    $payments = Mockery::mock(PaymentsClient::class);
    $client->payments = $payments;
    $payments->shouldReceive('create')->andThrow(new Exception('Payment error'));

    expect(fn() => $this->square->payFromPaymentProfile($order, []))
        ->toThrow(ApplicationException::class, 'Sorry, there was an error processing your payment. Please try again later');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment error -> Payment error',
        'is_success' => 0,
    ]);
});

it('logs a square api error when paying from a profile', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $order = Order::factory()
        ->for(Customer::factory()->create(), 'customer')
        ->for($this->payment, 'payment_method')
        ->create(['order_total' => 100]);
    PaymentProfile::factory()->create([
        'customer_id' => $order->customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['card_id' => 'card123', 'customer_id' => 'cust123'],
    ]);

    $client = setupSquareClient();
    $payments = Mockery::mock(PaymentsClient::class);
    $client->payments = $payments;
    $payments->shouldReceive('create')->andThrow(squareApiException('Payment error', jsonBody: true));

    expect(fn() => $this->square->payFromPaymentProfile($order, []))
        ->toThrow(ApplicationException::class, 'Sorry, there was an error processing your payment. Please try again later');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->order_id,
        'message' => 'Payment error -> Payment error',
        'is_success' => 0,
    ]);
});

it('builds a sandbox square client', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'sandbox-token';
    $extended = false;
    $this->square->bindEvent('square.extendGateway', function() use (&$extended): void {
        $extended = true;
    });

    $client = (new ReflectionMethod(Square::class, 'createClient'))->invoke($this->square);

    expect($client)->toBeInstanceOf(SquareClient::class)
        ->and($extended)->toBeTrue();
});

it('builds a live square client', function(): void {
    $this->payment->transaction_mode = 'live';
    $this->payment->live_access_token = 'live-token';

    $client = (new ReflectionMethod(Square::class, 'createClient'))->invoke($this->square);

    expect($client)->toBeInstanceOf(SquareClient::class);
});

it('deletes payment profile successfully', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $customer = Customer::factory()->create();
    $profile = PaymentProfile::factory()->create([
        'customer_id' => $customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['customer_id' => 'cust123', 'card_id' => 'card123'],
    ]);
    $client = setupSquareClient();
    $cards = Mockery::mock(CardsClient::class);
    $client->cards = $cards;
    $cards->shouldReceive('disable')->once();

    $result = $this->square->deletePaymentProfile($customer, $profile);

    expect($result)->toBeNull();
});

it('throws exception when deleting payment profile fails', function(): void {
    $this->payment->transaction_mode = 'test';
    $this->payment->test_access_token = 'test_access_token';
    $customer = Customer::factory()->create();
    $profile = PaymentProfile::factory()->create([
        'customer_id' => $customer->getKey(),
        'payment_id' => $this->payment->getKey(),
        'profile_data' => ['customer_id' => 'cust123', 'card_id' => 'card123'],
    ]);
    $client = setupSquareClient();
    $cards = Mockery::mock(CardsClient::class);
    $client->cards = $cards;
    $cards->shouldReceive('disable')->andThrow(squareApiException('Deleting card failed'));

    expect(fn() => $this->square->deletePaymentProfile($customer, $profile))
        ->toThrow(ApplicationException::class, 'Square Delete Payment Card Error: Deleting card failed');
});
