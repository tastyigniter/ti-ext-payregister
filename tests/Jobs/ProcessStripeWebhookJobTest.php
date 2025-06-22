<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Tests\Jobs;

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Jobs\ProcessStripeWebhookJob;
use Igniter\PayRegister\Models\Payment;
use Illuminate\Support\Facades\Event;
use LogicException;

it('handles payment intent succeeded with valid order and successful status', function(): void {
    Event::fake();
    $order = Order::factory()->create(['payment' => 'stripe']);
    $order->payment_method->applyGatewayClass();

    $payload = [
        'data' => [
            'object' => [
                'metadata' => ['order_id' => $order->getKey()],
                'status' => 'succeeded',
            ],
        ],
    ];

    $job = new ProcessStripeWebhookJob($order->payment_method, 'payment_intent.succeeded', $payload);
    $job->handle();

    Event::assertDispatched('payregister.stripe.webhook.handle');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->getKey(),
        'message' => 'Payment successful via webhook',
        'is_success' => 1,
        'is_refundable' => 1,
    ]);
});

it('handles payment intent succeeded with valid order and requires capture status', function(): void {
    Event::fake();
    $order = Order::factory()->create(['payment' => 'stripe']);
    $order->payment_method->applyGatewayClass();

    $payload = [
        'data' => [
            'object' => [
                'metadata' => ['order_id' => $order->getKey()],
                'status' => 'requires_capture',
            ],
        ],
    ];

    $job = new ProcessStripeWebhookJob($order->payment_method, 'payment_intent.succeeded', $payload);
    $job->handle();

    Event::assertDispatched('payregister.stripe.webhook.handle');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->getKey(),
        'message' => 'Payment authorized via webhook',
        'is_success' => 1,
        'is_refundable' => 0,
    ]);
});

it('skips processing when order is already processed', function(): void {
    Event::fake();
    $order = Order::factory()->create(['payment' => 'stripe']);
    $order->payment_method->applyGatewayClass();
    $order->markAsPaymentProcessed();

    $payload = [
        'data' => [
            'object' => [
                'metadata' => ['order_id' => $order->getKey()],
                'status' => 'succeeded',
            ],
        ],
    ];

    $job = new ProcessStripeWebhookJob($order->payment_method, 'payment_intent.succeeded', $payload);
    $job->handle();

    Event::assertDispatched('payregister.stripe.webhook.handle');

    $this->assertDatabaseHas('payment_logs', [
        'order_id' => $order->getKey(),
        'message' => 'Payment already processed, skipping webhook',
        'is_success' => 0,
        'is_refundable' => 0,
    ]);
});

it('does nothing when order is not found', function(): void {
    Event::fake();

    $payload = [
        'data' => [
            'object' => [
                'metadata' => ['order_id' => 123],
                'status' => 'succeeded',
            ],
        ],
    ];

    $job = new ProcessStripeWebhookJob(mock(Payment::class), 'payment_intent.succeeded', $payload);
    $job->handle();

    Event::assertDispatched('payregister.stripe.webhook.handle');
});

it('throws exception when webhook handler method does not exist', function(): void {
    $job = new ProcessStripeWebhookJob(mock(Payment::class), 'non_existent_event', []);

    expect(fn() => $job->checkMethod())->toThrow(LogicException::class);
});
