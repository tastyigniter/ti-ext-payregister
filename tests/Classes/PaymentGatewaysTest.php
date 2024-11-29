<?php

namespace Igniter\PayRegister\Tests\Classes;

use Igniter\PayRegister\Classes\PaymentGateways;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Tests\Payments\Fixtures\TestPayment;
use Igniter\System\Classes\ExtensionManager;
use Mockery;

beforeEach(function() {
    $this->paymentGateways = new PaymentGateways();
});

it('returns null if gateway not found', function() {
    $result = $this->paymentGateways->findGateway('nonexistent');

    expect($result)->toBeNull();
});

it('returns gateway details if gateway found', function() {
    $gateway = ['code' => 'test_gateway', 'class' => TestPayment::class];
    $this->paymentGateways->registerGateways('test_owner', [$gateway['class'] => $gateway]);

    $result = $this->paymentGateways->findGateway('test_gateway');

    expect($result['code'])->toBe($gateway['code'])
        ->and($result['class'])->toBe($gateway['class'])
        ->and($result['owner'])->toBe('test_owner')
        ->and($result['object'])->toBeInstanceOf(TestPayment::class);
});

it('returns list of gateway objects', function() {
    $gateway = ['code' => 'test_gateway', 'class' => TestPayment::class];
    $this->paymentGateways->registerGateways('test_owner', [$gateway['class'] => $gateway]);

    $result = $this->paymentGateways->listGatewayObjects();

    expect($result)->toHaveKey('test_gateway')
        ->and($result['test_gateway'])->toBeInstanceOf(TestPayment::class);
});

it('loads gateways from extensions', function() {
    $extensionManager = Mockery::mock(ExtensionManager::class);
    $extensionManager->shouldReceive('getExtensions')->andReturn([
        'test_extension' => new class
        {
            public function registerPaymentGateways()
            {
                return [
                    TestPayment::class => [
                        'code' => 'test_gateway',
                    ],
                ];
            }
        },
    ]);
    app()->instance(ExtensionManager::class, $extensionManager);

    $this->paymentGateways->listGateways();

    $result = $this->paymentGateways->findGateway('test_gateway');

    expect($result)->toBeArray()
        ->and($result['code'])->toBe('test_gateway');
});

it('executes entry point and returns response', function() {
    Payment::factory()->create([
        'code' => 'test_code',
        'class_name' => TestPayment::class,
    ]);

    $result = PaymentGateways::runEntryPoint('test_endpoint', 'test/uri');

    expect($result)->toBe('test_endpoint');
});

it('returns 403 response if entry point not found', function() {
    $result = PaymentGateways::runEntryPoint('invalid_code', 'test/uri');

    expect($result->getStatusCode())->toBe(403);
});
