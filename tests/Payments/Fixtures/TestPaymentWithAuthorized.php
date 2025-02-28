<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Tests\Payments\Fixtures;

use Override;
use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithAuthorizedPayment;

class TestPaymentWithAuthorized extends BasePaymentGateway
{
    use WithAuthorizedPayment;

    #[Override]
    public function defineFieldsConfig(): string
    {
        return __DIR__.'/../../_fixtures/fields';
    }

    public function shouldAuthorizePayment(): bool
    {
        return true;
    }

    public function captureAuthorizedPayment(Order $order): bool
    {
        return true;
    }

    public function cancelAuthorizedPayment(Order $order): bool
    {
        return true;
    }
}
