<?php namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use ApplicationException;
use Exception;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Omnipay\Omnipay;

class Square extends BasePaymentGateway
{
    use EventEmitter;
    use PaymentHelpers;

    public function getHiddenFields()
    {
        return [
            'square_card_nonce' => '',
            'square_card_token' => '',
        ];
    }

    public function isTestMode()
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

    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * @param self $host
     * @param \Main\Classes\MainController $controller
     */
    public function beforeRenderPaymentForm($host, $controller)
    {
        $endpoint = $this->isTestMode() ? 'squareupsandbox' : 'squareup';
        $controller->addJs('https://js.'.$endpoint.'.com/v2/paymentform', 'square-js');
        $controller->addJs('$/igniter/payregister/assets/process.square.js', 'process-square-js');
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @throws \ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        if (array_get($data, 'create_payment_profile', 0) == 1 AND $order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['customerCardId'] = array_get($profile->profile_data, 'card_id');
            $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        }
        else {
            $fields['nonce'] = array_get($data, 'square_card_nonce');
            $fields['token'] = array_get($data, 'square_card_token');
        }

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            $this->handlePaymentResponse($response, $order, $host, $fields);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    //
    // Payment Profiles
    //

    /**
     * {@inheritDoc}
     */
    public function supportsPaymentProfiles()
    {
        return TRUE;
    }

    /**
     * {@inheritDoc}
     */
    public function updatePaymentProfile($customer, $data)
    {
        return $this->handleUpdatePaymentProfile($customer, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function deletePaymentProfile($customer, $profile)
    {
        $this->handleDeletePaymentProfile($customer, $profile);
    }

    /**
     * {@inheritDoc}
     */
    public function payFromPaymentProfile($order, $data = [])
    {
        $host = $this->getHostObject();
        $profile = $host->findPaymentProfile($order->customer);

        if (!$profile OR !$profile->hasProfileData())
            throw new ApplicationException('Payment profile not found');

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['customerCardId'] = array_get($profile->profile_data, 'card_id');
        $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');

        try {
            $gateway = $this->createGateway();
            $response = $gateway->purchase($fields)->send();

            $this->handlePaymentResponse($response, $order, $host, $fields);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
        }
    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $response = FALSE;
        $gateway = $this->createGateway();
        $newCustomerRequired = !array_get($profileData, 'customer_id');

        if (!$newCustomerRequired) {
            $response = $gateway->fetchCustomer([
                'customerReference' => array_get($profileData, 'customer_id'),
            ])->send();

            if (!$response->isSuccessful())
                $newCustomerRequired = TRUE;
        }

        if ($newCustomerRequired) {
            $response = $gateway->createCustomer([
                'firstName' => $customer->first_name,
                'lastName' => $customer->last_name,
                'email' => $customer->email,
            ])->send();

            if (!$response->isSuccessful()) {
                throw new ApplicationException($response->getMessage());
            }
        }

        return $response;
    }

    protected function createOrFetchCard($customerId, $profileData, $data)
    {
        $cardId = array_get($profileData, 'card_id');
        $nonce = array_get($data, 'square_card_nonce');

        $response = FALSE;
        $gateway = $this->createGateway();
        $newCardRequired = !$cardId;

        if (!$newCardRequired) {
            $response = $gateway->fetchCard([
                'card' => $cardId,
                'customerReference' => $customerId,
            ])->send();

            if (!$response->isSuccessful())
                $newCardRequired = TRUE;
        }

        if ($newCardRequired) {
            $response = $gateway->createCard([
                'cardholderName' => array_get($data, 'first_name').' '.array_get($data, 'last_name'),
                'customerReference' => $customerId,
                'card' => $nonce,
            ])->send();

            if (!$response->isSuccessful())
                throw new ApplicationException($response->getMessage());
        }

        return $response;
    }

    /**
     * @param \Admin\Models\Payment_profiles_model $profile
     * @param array $profileData
     * @param array $cardData
     * @return \Admin\Models\Payment_profiles_model
     */
    protected function updatePaymentProfileData($profile, $profileData = [], $cardData = [])
    {
        $profile->card_brand = strtolower(array_get($cardData, 'card.card_brand'));
        $profile->card_last4 = array_get($cardData, 'card.last_4');
        $profile->setProfileData($profileData);

        return $profile;
    }

    //
    //
    //

    /**
     * @return \Omnipay\Square\Gateway|\Omnipay\Common\GatewayInterface
     */
    protected function createGateway()
    {
        $gateway = Omnipay::create('Square');

        $gateway->setTestMode($this->isTestMode());
        $gateway->setAppId($this->getAppId());
        $gateway->setAccessToken($this->getAccessToken());
        $gateway->setLocationId($this->getLocationId());

        return $gateway;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        $fields = [
            'idempotencyKey' => uniqid(),
            'amount' => number_format($order->order_total, 2, '.', ''),
            'currency' => currency()->getUserCurrency(),
            'note' => 'Payment for Order '.$order->order_id,
            'referenceId' => (string)$order->order_id,
        ];

        $this->fireSystemEvent('payregister.square.extendFields', [&$fields, $order, $data]);

        return $fields;
    }
}