<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithPaymentProfile;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\User\Models\Customer;
use Override;
use Square\Cards\Requests\CreateCardRequest;
use Square\Cards\Requests\DisableCardsRequest;
use Square\Cards\Requests\GetCardsRequest;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Customers\Requests\GetCustomersRequest;
use Square\Environments;
use Square\Exceptions\SquareApiException;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\SquareClient;
use Square\Types\Card;
use Square\Types\CreateCardResponse;
use Square\Types\CreateCustomerResponse;
use Square\Types\CreatePaymentResponse;
use Square\Types\GetCardResponse;
use Square\Types\GetCustomerResponse;
use Square\Types\Money;
use Square\Types\RefundPaymentResponse;

class Square extends BasePaymentGateway
{
    use WithPaymentProfile;
    use WithPaymentRefund;

    public static ?string $paymentFormView = 'igniter.payregister::_partials.square.payment_form';

    #[Override]
    public function defineFieldsConfig(): string
    {
        return 'igniter.payregister::/models/square';
    }

    public function getHiddenFields(): array
    {
        return [
            'square_card_nonce' => '',
            'square_card_token' => '',
        ];
    }

    public function isTestMode(): bool
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getAppId()
    {
        return $this->isTestMode() ? $this->model->test_app_id : $this->model->live_app_id;
    }

    public function getAccessToken()
    {
        return $this->isTestMode() ? $this->model->test_access_token : $this->model->live_access_token;
    }

    public function getLocationId()
    {
        return $this->isTestMode() ? $this->model->test_location_id : $this->model->live_location_id;
    }

    /**
     * @param self $host
     * @param MainController $controller
     */
    #[Override]
    public function beforeRenderPaymentForm($host, $controller): void
    {
        $endpoint = $this->isTestMode() ? 'sandbox.' : '';
        $controller->addJs('https://'.$endpoint.'web.squarecdn.com/v1/square.js', 'square-js');
        $controller->addJs('igniter.payregister::/js/process.square.js', 'process-square-js');
    }

    #[Override]
    public function completesPaymentOnClient(): bool
    {
        return true;
    }

    protected function createPayment(array $fields, $order, $host): CreatePaymentResponse
    {
        $client = $this->createClient();

        $idempotencyKey = str_random();

        $values = [
            'sourceId' => $fields['sourceId'],
            'idempotencyKey' => $idempotencyKey,
            'amountMoney' => new Money([
                'amount' => (int)($fields['amount'] * 100),
                'currency' => $fields['currency'],
            ]),
            'autocomplete' => true,
            'locationId' => $this->getLocationId(),
            'referenceId' => $fields['referenceId'],
            'note' => $order->customer_name,
        ];

        if (isset($fields['tip'])) {
            $values['tipMoney'] = new Money([
                'amount' => (int)($fields['tip'] * 100),
                'currency' => $fields['currency'],
            ]);
        }

        if (isset($fields['customerReference'])) {
            $values['customerId'] = $fields['customerReference'];
        }

        if (isset($fields['token'])) {
            $values['verificationToken'] = $fields['token'];
        }

        $body = new CreatePaymentRequest($values);

        $this->fireSystemEvent('payregister.square.extendCreatePaymentRequest', [$body, $client->payments]);

        return $client->payments->create($body);
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param Payment $host
     * @param Order $order
     *
     * @throws ApplicationException
     */
    #[Override]
    public function processPaymentForm($data, $host, $order): void
    {
        $this->validateApplicableFee($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        if (array_get($data, 'create_payment_profile', 0) == 1 && $order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['sourceId'] = array_get($profile->profile_data, 'card_id');
            $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        } else {
            $fields['sourceId'] = array_get($data, 'square_card_nonce');
            $fields['token'] = array_get($data, 'square_card_token');
        }

        try {
            $response = $this->createPayment($fields, $order, $host);

            $this->handlePaymentResponse($response, $order, $host, $fields, true);

            return;
        } catch (SquareApiException $ex) {
            $order->logPaymentAttempt('Payment error -> '.$this->squareErrorDetail($ex), 0, $fields, $this->exceptionBody($ex));
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
        }

        throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later');
    }

    //
    // Payment Profiles
    //

    #[Override]
    public function supportsPaymentProfiles(): bool
    {
        return true;
    }

    #[Override]
    public function updatePaymentProfile(Customer $customer, array $data = []): PaymentProfile
    {
        return $this->handleUpdatePaymentProfile($customer, $data);
    }

    #[Override]
    public function deletePaymentProfile(Customer $customer, PaymentProfile $profile)
    {
        return $this->handleDeletePaymentProfile($customer, $profile);
    }

    #[Override]
    public function payFromPaymentProfile(Order $order, array $data = []): void
    {
        $host = $this->getHostObject();
        $profile = $host->findPaymentProfile($order->customer);

        if (!$profile || !$profile->hasProfileData()) {
            throw new ApplicationException('Payment profile not found');
        }

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['sourceId'] = array_get($profile->profile_data, 'card_id');
        $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');

        try {
            $response = $this->createPayment($fields, $order, $host);

            $this->handlePaymentResponse($response, $order, $host, $fields, true);

            return;
        } catch (SquareApiException $ex) {
            $order->logPaymentAttempt('Payment error -> '.$this->squareErrorDetail($ex), 0, $fields, $this->exceptionBody($ex));
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
        }

        throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later');
    }

    protected function createOrFetchCustomer($profileData, $customer): CreateCustomerResponse|GetCustomerResponse
    {
        $client = $this->createClient();
        $customerId = array_get($profileData, 'customer_id');

        if ($customerId) {
            try {
                return $client->customers->get(new GetCustomersRequest([
                    'customerId' => $customerId,
                ]));
            } catch (SquareApiException) {
                // The stored customer is missing, so create a new one.
            }
        }

        try {
            return $client->customers->create(new CreateCustomerRequest([
                'givenName' => $customer->first_name,
                'familyName' => $customer->last_name,
                'emailAddress' => $customer->email,
                'referenceId' => 'SqCustRef#'.$customer->customer_id,
            ]));
        } catch (SquareApiException $exception) {
            throw new ApplicationException('Square Customer Create Error: '.$this->squareErrorDetail($exception), $exception->getCode(), $exception);
        }
    }

    protected function createOrFetchCard(?string $customerId, ?string $referenceId, $profileData, array $data): CreateCardResponse|GetCardResponse
    {
        $cardId = array_get($profileData, 'card_id');
        $nonce = array_get($data, 'square_card_nonce');

        $client = $this->createClient();

        if ($cardId) {
            try {
                return $client->cards->get(new GetCardsRequest([
                    'cardId' => $cardId,
                ]));
            } catch (SquareApiException) {
                // The stored card is missing, so create a new one.
            }
        }

        try {
            return $client->cards->create(new CreateCardRequest([
                'idempotencyKey' => str_random(),
                'sourceId' => $nonce,
                'card' => new Card([
                    'cardholderName' => $data['first_name'].' '.$data['last_name'],
                    'customerId' => $customerId,
                    'referenceId' => $referenceId,
                ]),
            ]));
        } catch (SquareApiException $exception) {
            throw new ApplicationException('Square Create Payment Card Error: '.$this->squareErrorDetail($exception), $exception->getCode(), $exception);
        }
    }

    protected function updatePaymentProfileData(PaymentProfile $profile, array $profileData, Card $cardData): PaymentProfile
    {
        $profile->card_brand = strtolower((string)$cardData->getCardBrand());
        $profile->card_last4 = $cardData->getLast4();
        $profile->setProfileData($profileData);

        return $profile;
    }

    protected function deletePaymentProfileData(PaymentProfile $profile): PaymentProfile
    {
        $profile->setProfileData([]);

        return $profile;
    }

    //
    //
    //

    #[Override]
    public function processRefundForm($data, $order, $paymentLog): void
    {
        if (!is_null($paymentLog->refunded_at)) {
            throw new ApplicationException('Nothing to refund, payment already refunded');
        }

        if (!is_array($paymentLog->response) || array_get($paymentLog->response, 'payment.status') !== 'COMPLETED') {
            throw new ApplicationException('No charge to refund');
        }

        $paymentChargeId = array_get($paymentLog->response, 'payment.id');
        $fields = $this->getPaymentRefundFields($order, $data);

        try {
            $body = new RefundPaymentRequest([
                'idempotencyKey' => str_random(),
                'amountMoney' => new Money([
                    'amount' => $fields['amount'],
                    'currency' => $fields['currency'],
                ]),
                'paymentId' => $paymentChargeId,
                'reason' => $fields['reason'],
            ]);

            $client = $this->createClient();
            $response = $client->refunds->refundPayment($body);

            $message = sprintf('Payment %s refunded successfully -> (%s: %s)',
                $paymentChargeId,
                array_get($data, 'refund_type'),
                $response->getRefund()?->getId(),
            );

            $order->logPaymentAttempt($message, 1, $fields, $this->responsePayload($response));
            $paymentLog->markAsRefundProcessed();
        } catch (SquareApiException $e) {
            logger()->error($e);
            $order->logPaymentAttempt('Refund failed -> Refund failed', 0, $fields, []);
        } catch (Exception $e) {
            logger()->error($e);
            $order->logPaymentAttempt('Refund failed -> '.$e->getMessage(), 0, $fields, []);
        }
    }

    protected function getPaymentRefundFields($order, $data): array
    {
        $refundAmount = array_get($data, 'refund_type') !== 'full'
            ? array_get($data, 'refund_amount') : $order->order_total;

        throw_if($refundAmount > $order->order_total, new ApplicationException(
            'Refund amount should be be less than or equal to the order total',
        ));

        $fields = [
            'amount' => (int)(number_format($refundAmount, 2, '', '') * 100),
            'currency' => currency()->getUserCurrency(),
            'reason' => array_get($data, 'refund_reason'),
        ];

        $eventResult = $this->fireSystemEvent('payregister.square.extendRefundFields', [$fields, $order, $data], false);
        if (is_array($eventResult) && array_filter($eventResult)) {
            return array_merge($fields, ...$eventResult);
        }

        return $fields;
    }

    //
    //
    //
    protected function createClient(): SquareClient
    {
        $client = new SquareClient(
            token: $this->getAccessToken(),
            options: [
                'baseUrl' => $this->isTestMode() ? Environments::Sandbox->value : Environments::Production->value,
            ],
        );

        $this->fireSystemEvent('payregister.square.extendGateway', [$client]);

        return $client;
    }

    protected function getPaymentFormFields($order, $data = []): array
    {
        // just for Square - record tips as separate amount
        $orderAmount = $order->order_total;
        $tipAmount = 0;
        foreach ($order->getOrderTotals() as $ot) {
            if ($ot->code == 'tip') {
                $tipAmount = $ot->value;
                $orderAmount -= $tipAmount;
            }
        }

        $fields = [
            'idempotencyKey' => uniqid(),
            'amount' => (int)(number_format($orderAmount, 2, '.', '') * 100),
            'currency' => currency()->getUserCurrency(),
            'note' => 'Payment for Order '.$order->order_id,
            'referenceId' => (string)$order->order_id,
        ];

        if ($tipAmount) {
            $fields['tip'] = (int)(number_format($tipAmount, 2, '.', '') * 100);
        }

        $this->fireSystemEvent('payregister.square.extendFields', [&$fields, $order, $data]);

        return $fields;
    }

    protected function handlePaymentResponse(CreatePaymentResponse $response, Order $order, Payment $host, array $fields, bool $isRefundable = false): void
    {
        $order->logPaymentAttempt('Payment successful', 1, $fields, $this->responsePayload($response), $isRefundable);
        $order->updateOrderStatus($host->order_status, ['notify' => false]);
        $order->markAsPaymentProcessed();
    }

    protected function handleUpdatePaymentProfile($customer, array $data)
    {
        $profile = $this->getHostObject()->findPaymentProfile($customer);
        $profileData = (array)$profile?->profile_data;

        $response = $this->createOrFetchCustomer($profileData, $customer);

        $customerId = $response->getCustomer()->getId();
        $referenceId = $response->getCustomer()->getReferenceId();

        $response = $this->createOrFetchCard($customerId, $referenceId, $profileData, $data);

        $cardData = $response->getCard();
        $cardId = $response->getCard()->getId();

        if (!$profile) {
            $profile = $this->getHostObject()->initPaymentProfile($customer);
        }

        $this->updatePaymentProfileData($profile, [
            'customer_id' => $customerId,
            'card_id' => $cardId,
        ], $cardData);

        return $profile;
    }

    protected function handleDeletePaymentProfile($customer, PaymentProfile $profile)
    {
        $cardId = $profile['profile_data']['card_id'];
        $client = $this->createClient();

        try {
            $client->cards->disable(new DisableCardsRequest([
                'cardId' => $cardId,
            ]));
        } catch (SquareApiException $exception) {
            throw new ApplicationException('Square Delete Payment Card Error: '.$this->squareErrorDetail($exception), $exception->getCode(), $exception);
        }

        $this->deletePaymentProfileData($profile);
    }

    protected function squareErrorDetail(SquareApiException $exception): string
    {
        return (string)$exception->getErrors()[0]->getDetail();
    }

    protected function exceptionBody(SquareApiException $exception): array
    {
        $body = $exception->getBody();

        if (is_string($body)) {
            $body = json_decode($body, true);
        }

        return is_array($body) ? $body : [];
    }

    protected function responsePayload(CreatePaymentResponse|RefundPaymentResponse $response): array
    {
        $payload = json_decode($response->toJson(), true);

        return is_array($payload) ? $payload : [];
    }
}
