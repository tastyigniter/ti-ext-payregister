<?php

namespace Igniter\PayRegister\Tests\Classes;

use Igniter\Admin\Models\Status;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Database\Model;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Tests\Payments\Fixtures\TestPayment;
use Illuminate\Support\Facades\URL;
use Mockery;

beforeEach(function() {
    $this->model = Mockery::mock(Model::class)->makePartial();
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

it('defines fields config', function() {
    $model = mock(Model::class)->makePartial();
    $gateway = new class($model) extends BasePaymentGateway
    {
        public function __construct(?Model $model = null) {}
    };

    $result = $gateway->defineFieldsConfig();

    expect($result)->toEqual('fields');
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

it('throws exception when processPaymentForm is not implemented', function() {
    $data = [];
    $host = Mockery::mock(Model::class);
    $order = Mockery::mock(Order::class);

    expect(fn() => $this->gateway->processPaymentForm($data, $host, $order))->toThrow(\LogicException::class);
});

it('returns null when beforeRenderPaymentForm is not implemented', function() {
    $host = Mockery::mock(Model::class);
    $controller = MainController::getController();

    $result = $this->gateway->beforeRenderPaymentForm($host, $controller);

    expect($result)->toBeNull();
});

it('renders payment form', function() {
    $controller = MainController::getController();
    $viewName = 'igniter-orange::_partials.payregister.stripe';
    $factory = Mockery::mock(\Illuminate\View\Factory::class);
    $factory->shouldReceive('exists')->with($viewName)->andReturnTrue();
    $factory->shouldReceive('make')->with($viewName, ['paymentMethod' => $this->model], [])->andReturnSelf();
    app()->instance('view', $factory);

    $this->model->shouldReceive('getAttribute')->with('code')->andReturn('stripe');
    $this->model->shouldReceive('getAttribute')->with('class_name')->andReturn(TestPayment::class);

    $result = $this->gateway->renderPaymentForm($this->model, $controller);

    expect($result)->toBe($factory);
});

it('has payment form blade view under payregister partials folder', function() {
    $viewName = 'igniter-orange::_partials.payregister.stripe';
    $factory = Mockery::mock(\Illuminate\View\Factory::class);
    $factory->shouldReceive('exists')->with($viewName)->andReturnTrue();
    app()->instance('view', $factory);

    $this->model->shouldReceive('getAttribute')->with('code')->andReturn('stripe');
    $result = $this->gateway->getPaymentFormViewName();

    expect($result)->toStartWith($viewName);
});

it('guesses payment form blade view', function() {
    $viewName = 'igniter.payregister::test-payment.payment_form';
    $factory = Mockery::mock(\Illuminate\View\Factory::class);
    $factory->shouldReceive('exists')->with($viewName)->andReturnTrue();
    $factory->shouldReceive('exists')->with('igniter-orange::_partials.payregister.test-payment')->andReturnFalse();
    app()->instance('view', $factory);
    $this->model->shouldReceive('getAttribute')->with('code')->andReturn('test-payment');
    $this->model->shouldReceive('getAttribute')->with('class_name')->andReturn(TestPayment::class);

    $result = $this->gateway->getPaymentFormViewName();

    expect($result)->toStartWith($viewName);
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
