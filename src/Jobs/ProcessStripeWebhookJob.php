<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Jobs;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;

class ProcessStripeWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected Payment $payment,
        protected string $eventName,
        protected array $payload,
    ) {}

    public function getMethod(): string
    {
        return 'handle'.Str::studly(str_replace('.', '_', $this->eventName));
    }

    public function checkMethod(): void
    {
        throw_unless(method_exists($this, $this->getMethod()), new LogicException(sprintf(
            'Webhook handler method %s does not exist in %s', $this->getMethod(), static::class,
        )));
    }

    public function handle(): void
    {
        $this->checkMethod();

        $this->{$this->getMethod()}($this->payload);

        Event::dispatch('payregister.stripe.webhook.handle', [$this]);
    }

    protected function handlePaymentIntentSucceeded(array $payload)
    {
        /** @var null|Order $order */
        $order = Order::find($payload['data']['object']['metadata']['order_id']);
        if (!$order) {
            return;
        }

        if ($order->isPaymentProcessed()) {
            $order->logPaymentAttempt('Payment already processed, skipping webhook', 0, [], $payload['data']['object']);

            return;
        }

        if ($payload['data']['object']['status'] === 'requires_capture') {
            $order->logPaymentAttempt('Payment authorized via webhook', 1, [], $payload['data']['object']);
        } else {
            $order->logPaymentAttempt('Payment successful via webhook', 1, [], $payload['data']['object'], true);
        }

        $order->updateOrderStatus($this->payment->order_status, ['notify' => false]);
        $order->markAsPaymentProcessed();
    }
}
