<?php

namespace Igniter\PayRegister\Payments;

use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Mollie\Api\MollieApiClient;

class Mollie extends BasePaymentGateway
{
    use WithPaymentRefund;

    public static ?string $paymentFormView = 'igniter.payregister::_partials.mollie.payment_form';

    public function defineFieldsConfig()
    {
        return 'igniter.payregister::/models/mollie';
    }

    public function registerEntryPoints()
    {
        return [
            'mollie_return_url' => 'processReturnUrl',
            'mollie_notify_url' => 'processNotifyUrl',
        ];
    }

    public function isTestMode()
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getApiKey()
    {
        return $this->isTestMode() ? $this->model->test_api_key : $this->model->live_api_key;
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Igniter\PayRegister\Models\Payment $host
     * @param \Igniter\Cart\Models\Order $order
     *
     * @return bool|\Illuminate\Http\RedirectResponse
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validateApplicableFee($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        if ($order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['customerId'] = array_get($profile->profile_data, 'customer_id');
        }

        try {
            $payment = $this->createClient()->payments->create($fields);

            session()->put('mollie.payment_id', $payment->id);

            if ($payment->isOpen()) {
                return Redirect::to($payment->getCheckoutUrl());
            }

            $order->logPaymentAttempt('Payment error -> '.$payment->getMessage(), 0, $fields, [
                'id' => $payment->id,
                'status' => $payment->status,
                'method' => $payment->method,
                'amount' => $payment->amount,
            ]);
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields);
        }

        throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
    }

    public function processReturnUrl($params)
    {
        $hash = $params[0] ?? null;
        $redirectPage = input('redirect') ?: 'checkout.checkout';
        $cancelPage = input('cancel') ?: 'checkout.checkout';

        $order = $this->createOrderModel()->whereHash($hash)->first();

        try {
            throw_unless($order, new ApplicationException('No order found'));

            throw_if(
                !($paymentMethod = $order->payment_method) || !$paymentMethod->getGatewayObject() instanceof Mollie,
                new ApplicationException('No valid payment method found'),
            );

            throw_unless(
                $payment = $this->createClient()->payments->get(session()->get('mollie.payment_id')),
                new ApplicationException('Missing payment id in query parameters'),
            );

            throw_if($order->isPaymentProcessed(), new ApplicationException('Payment has already been processed'));

            if ($payment->isPaid() && data_get($payment->metadata, 'order_id') == $order->order_id) {
                $order->logPaymentAttempt('Payment successful', 1, [], [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                ], true);
                $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
                $order->markAsPaymentProcessed();
            }

            return Redirect::to(page_url($redirectPage, [
                'id' => $order->getKey(),
                'hash' => $order->hash,
            ]));
        } catch (Exception $ex) {
            $order?->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, [], request()->input());
            flash()->warning($ex->getMessage())->important();
        }

        return Redirect::to(page_url($cancelPage));
    }

    public function processNotifyUrl($params)
    {
        $hash = $params[0] ?? null;
        $order = $this->createOrderModel()->whereHash($hash)->first();

        throw_unless($order, new ApplicationException('No order found'));

        throw_if(
            !($paymentMethod = $order->payment_method) || !$paymentMethod->getGatewayObject() instanceof Mollie,
            new ApplicationException('No valid payment method found'),
        );

        throw_unless(
            $payment = $this->createClient()->payments->get(request()->input('id', '')),
            new ApplicationException('Payment not found or missing payment id in query parameters'),
        );

        $response = [
            'id' => $payment->id,
            'status' => $payment->status,
            'method' => $payment->method,
            'amount' => $payment->amount,
        ];

        if (!$order->isPaymentProcessed()) {
            if ($payment->isPaid()) {
                $order->logPaymentAttempt('Payment successful', 1, request()->input(), $response, true);
                $order->updateOrderStatus($paymentMethod->order_status, ['notify' => false]);
                $order->markAsPaymentProcessed();
            } else {
                $order->logPaymentAttempt('Payment unsuccessful', 0, request()->input(), $response);
                $order->updateOrderStatus(setting('canceled_order_status'), ['notify' => false]);
            }
        }

        return Response::json(['success' => true]);
    }

    public function processRefundForm($data, $order, $paymentLog)
    {
        throw_if(
            !is_null($paymentLog->refunded_at) || !is_array($paymentLog->response),
            new ApplicationException('Nothing to refund'),
        );

        throw_if(
            array_get($paymentLog->response, 'status') !== 'paid',
            new ApplicationException('No charge to refund'),
        );

        $paymentId = array_get($paymentLog->response, 'id');
        $fields = $this->getPaymentRefundFields($order, $data);

        try {
            $payment = $this->createClient()->payments->get($paymentId);
            $response = $payment->refund($fields);

            $message = sprintf('Payment %s refund processed -> (%s: %s)',
                $paymentId, array_get($data, 'refund_type'), $response->id,
            );

            $order->logPaymentAttempt($message, 1, $fields, [
                'id' => $response->id,
                'status' => $response->status,
                'amount' => $response->amount,
            ]);
            $paymentLog->markAsRefundProcessed();
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Refund failed -> '.$ex->getMessage(), 0, $fields, []);

            throw new ApplicationException('Refund failed');
        }
    }

    //
    // Payment Profiles
    //

    /**
     * {@inheritdoc}
     */
    public function updatePaymentProfile($customer, $data)
    {
        $profile = $this->model->findPaymentProfile($customer);
        $profileData = $profile ? (array)$profile->profile_data : [];

        $response = $this->createOrFetchCustomer($profileData, $customer);
        $customerId = $response->id;

        if (!$profile) {
            $profile = $this->model->initPaymentProfile($customer);
        }

        $profile->setProfileData([
            'customer_id' => $customerId,
            'card_id' => str_random(16),
        ]);

        return $profile;
    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $response = false;
        $newCustomerRequired = !array_get($profileData, 'customer_id');
        $client = $this->createClient();

        if (!$newCustomerRequired) {
            if (!$response = $client->customers->get(array_get($profileData, 'customer_id'))) {
                $newCustomerRequired = true;
            }
        }

        if ($newCustomerRequired) {
            $fields = [
                'name' => $customer->full_name,
                'email' => $customer->email,
            ];

            if (!$response = $client->customers->create($fields)) {
                throw new ApplicationException('Unable to create customer');
            }
        }

        return $response;
    }

    //
    //
    //

    protected function createClient(): MollieApiClient
    {
        $client = resolve(MollieApiClient::class);
        $client->setApiKey($this->getApiKey());

        $this->fireSystemEvent('payregister.mollie.extendGateway', [$client]);

        return $client;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $notifyUrl = $this->makeEntryPointUrl('mollie_notify_url').'/'.$order->hash;
        $returnUrl = $this->makeEntryPointUrl('mollie_return_url').'/'.$order->hash;
        $returnUrl .= '?redirect='.array_get($data, 'successPage').'&cancel='.array_get($data, 'cancelPage');

        $fields = [
            'amount' => [
                'currency' => currency()->getUserCurrency(),
                'value' => number_format($order->order_total, 2, '.', ''),
            ],
            'description' => 'Payment for Order '.$order->order_id,
            'metadata' => [
                'order_id' => $order->order_id,
            ],
            'redirectUrl' => $returnUrl,
            'webhookUrl' => $notifyUrl,
        ];

        $this->fireSystemEvent('payregister.mollie.extendFields', [&$fields, $order, $data]);

        return $fields;
    }

    protected function getPaymentRefundFields($order, $data)
    {
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        throw_if($refundAmount > $order->order_total, new ApplicationException(
            'Refund amount should be be less than or equal to the order total',
        ));

        $fields = [
            'description' => array_get($data, 'refund_reason'),
            'amount' => [
                'currency' => currency()->getUserCurrency(),
                'value' => number_format($refundAmount, 2, '.', ''),
            ],
            'metadata' => [
                'order_id' => $order->getKey(),
            ],
        ];

        $eventResult = $this->fireSystemEvent('payregister.mollie.extendRefundFields', [$fields, $order, $data], false);
        if (is_array($eventResult) && array_filter($eventResult)) {
            $fields = array_merge($fields, ...$eventResult);
        }

        return $fields;
    }
}
