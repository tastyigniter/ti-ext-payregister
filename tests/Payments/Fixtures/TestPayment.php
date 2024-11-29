<?php

namespace Igniter\PayRegister\Tests\Payments\Fixtures;

use Igniter\PayRegister\Classes\BasePaymentGateway;

class TestPayment extends BasePaymentGateway
{
    public function defineFieldsConfig()
    {
        return __DIR__.'/../../_fixtures/fields';
    }

    public function registerEntryPoints()
    {
        return [
            'test_endpoint' => 'processReturnUrl',
        ];
    }

    public function processReturnUrl()
    {
        return 'test_endpoint';
    }
}
