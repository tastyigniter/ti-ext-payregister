<?php

namespace Igniter\PayRegister\Tests\Classes;

use Igniter\Admin\Models\Status;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Database\Model;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Illuminate\Support\Facades\URL;
use Mockery;

beforeEach(function() {
    $this->model = Mockery::mock(Model::class);
    $this->gateway = new class($this->model) extends BasePaymentGateway
    {
        public function defineFieldsConfig()
        {
            return __DIR__.'/../_fixtures/fields';
        }

        public function getModel()
        {
            return $this->createOrderModel();
        }

        public function getStatusModel()
        {
            return $this->createOrderStatusModel();
        }
    };
});

it('initializes with default config data if model does not exist', function() {
    $host = Mockery::mock(Model::class);
    $host->exists = false;
    $gateway = Mockery::mock(BasePaymentGateway::class)->makePartial();
    $gateway->shouldReceive('initConfigData')->with($host)->once();

    $gateway->initialize($host);
});

it('does not initialize config data if model exists', function() {
    $host = Mockery::mock(Model::class);
    $host->exists = true;

    $gateway = Mockery::mock(BasePaymentGateway::class)->makePartial();
    $gateway->shouldNotReceive('initConfigData');

    $gateway->initialize($host);
});

it('returns correct config fields', function() {
    $result = $this->gateway->getConfigFields();
    expect($result)->toBe([
        'test_field' => [
            'label' => 'Test Field',
            'type' => 'text',
        ],
    ]);
});

it('returns correct config rules', function() {
    $result = $this->gateway->getConfigRules();

    expect($result)->toBe([
        ['test_field', 'lang:igniter.payregister::default.stripe.label_test_field', 'required|string'],
    ]);
});

it('returns correct config validation attributes', function() {
    $result = $this->gateway->getConfigValidationAttributes();

    expect($result)->toBe([
        'test_field' => 'lang:igniter.payregister::default.stripe.label_test_field',
    ]);
});

it('returns correct config validation messages', function() {
    $result = $this->gateway->getConfigValidationMessages();

    expect($result)->toBe([
        'test_field.required' => 'lang:igniter.payregister::default.stripe.label_test_field',
        'test_field.string' => 'lang:igniter.payregister::default.stripe.label_test_field',
    ]);
});

it('creates correct entry point URL', function() {
    $code = 'test_code';
    $result = $this->gateway->makeEntryPointUrl($code);

    expect($result)->toBe(URL::to('ti_payregister/'.$code));
});

it('returns false for completes payment on client', function() {
    $result = $this->gateway->completesPaymentOnClient();

    expect($result)->toBeFalse();
});

it('creates an instance of the order model', function() {
    $result = $this->gateway->getModel();

    expect($result)->toBeInstanceOf(Order::class);
});

it('creates an instance of the order status model', function() {
    $result = $this->gateway->getStatusModel();

    expect($result)->toBeInstanceOf(Status::class);
});
