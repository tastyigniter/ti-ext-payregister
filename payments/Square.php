<?php

namespace Igniter\PayRegister\Payments;

use Admin\Classes\BasePaymentGateway;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\PayRegister\Traits\PaymentHelpers;
use Square\Environment;
use Square\Models;
use Square\SquareClient;

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
        $endpoint = $this->isTestMode() ? 'sandbox.' : '';
        $controller->addJs('https://'.$endpoint.'web.squarecdn.com/v1/square.js', 'square-js');
        $controller->addJs('$/igniter/payregister/assets/process.square.js', 'process-square-js');
    }

    public function completesPaymentOnClient()
    {
        return true;
    }

    public function createPayment($fields, $order, $host) {
        try {

            $client = $this->createClient();
            $paymentsApi = $client->getPaymentsApi();

            $body_idempotencyKey = md5(rand());

            $body_amountMoney = new Models\Money();
            $body_amountMoney->setAmount($fields['amount'] * 100);
            $body_amountMoney->setCurrency($fields['currency']);

            $body = new Models\CreatePaymentRequest(
                $fields['sourceId'],
                md5(rand()), // idempotency key
                $body_amountMoney
            );

            if(isset($fields['tip'])){
                $body_tipMoney = new Models\Money();
                $body_tipMoney->setAmount($fields['tip'] * 100);
                $body_tipMoney->setCurrency($fields['currency']);
                $body->setTipMoney($body_tipMoney);
            }

            $body->setAutocomplete(true);
            if(isset($fields['customerReference'])){
                $body->setCustomerId($fields['customerReference']);
            }
            if(isset($fields['token'])){
                $body->setVerificationToken($fields['token']);
            }

            $body->setLocationId($this->getLocationId());
            $body->setReferenceId($fields['referenceId']);
            $body->setNote($order->getCustomerNameAttribute(''));

            $response = $paymentsApi->createPayment($body);

            $this->handlePaymentResponse($response, $order, $host, $fields);
        }
        catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields, []);
            throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later');
        }
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @throws \Igniter\Flame\Exception\ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validatePaymentMethod($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);

        if (array_get($data, 'create_payment_profile', 0) == 1 && $order->customer) {
            $profile = $this->updatePaymentProfile($order->customer, $data);
            $fields['sourceId'] = array_get($profile->profile_data, 'card_id');
            $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');
        }
        else {
            $fields['sourceId'] = array_get($data, 'square_card_nonce');
            $fields['token'] = array_get($data, 'square_card_token');
        }

        $this->createPayment($fields, $order, $host);

    }

    //
    // Payment Profiles
    //

    /**
     * {@inheritdoc}
     */
    public function supportsPaymentProfiles()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function updatePaymentProfile($customer, $data)
    {
        return $this->handleUpdatePaymentProfile($customer, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function deletePaymentProfile($customer, $profile)
    {
        $this->handleDeletePaymentProfile($customer, $profile);
    }

    /**
     * {@inheritdoc}
     */
    public function payFromPaymentProfile($order, $data = [])
    {
        $host = $this->getHostObject();
        $profile = $host->findPaymentProfile($order->customer);

        if (!$profile || !$profile->hasProfileData())
            throw new ApplicationException('Payment profile not found');

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['sourceId'] = array_get($profile->profile_data, 'card_id');
        $fields['customerReference'] = array_get($profile->profile_data, 'customer_id');

        $this->createPayment($fields, $order, $host);

    }

    protected function createOrFetchCustomer($profileData, $customer)
    {
        $response = false;
        $client = $this->createClient();
        $customersApi = $client->getCustomersApi();

        $newCustomerRequired = !array_get($profileData, 'customer_id');

        if (!$newCustomerRequired) {

            $response = $customersApi->retrieveCustomer(array_get($profileData, 'customer_id'));

            if (!$response->isSuccess()) {
                $newCustomerRequired = true;
            }

        }

        if ($newCustomerRequired) {

            $body = new Models\CreateCustomerRequest();
            $body->setGivenName($customer->first_name);
            $body->setFamilyName($customer->last_name);
            $body->setEmailAddress($customer->email);

            $body->setReferenceId('SqCustRef#'.$customer->customer_id);

            $response = $customersApi->createCustomer($body);

            if (!$response->isSuccess()) {
                $errors = $response->getErrors();
                $errors = $errors[0]->getDetail();
                throw new ApplicationException('Square Customer Create Error: '.$errors);
            }

        }

        return $response->getResult();
    }

    protected function createOrFetchCard($customerId, $referenceId, $profileData, $data)
    {
        $cardId = array_get($profileData, 'card_id');
        $nonce = array_get($data, 'square_card_nonce');

        $response = false;
        $client = $this->createClient();
        $cardsApi = $client->getCardsApi();

        $newCardRequired = !$cardId;

        if (!$newCardRequired) {

            $response = $cardsApi->retrieveCard($cardId);

            if (!$response->isSuccess()) {
                $newCardRequired = true;
            }

        }

        if ($newCardRequired) {

            $body_card = new Models\Card();

            $body_card->setCardholderName($data['first_name'].' '.$data['last_name']);
            $body_card->setCustomerId($customerId);
            $body_card->setReferenceId($referenceId);

            $body = new Models\CreateCardRequest(
                md5(rand()),
                $nonce,
                $body_card
            );

            $response = $cardsApi->createCard($body);

            if (!$response->isSuccess()) {
                $errors = $response->getErrors();
                $errors = $errors[0]->getDetail();

                throw new ApplicationException('Square Create Payment Card Error '.$errors);
            }
   
        }

        return $response->getResult();
    }

    /**
     * @param \Admin\Models\Payment_profiles_model $profile
     * @param array $profileData
     * @param array $cardData
     * @return \Admin\Models\Payment_profiles_model
     */
    protected function updatePaymentProfileData($profile, $profileData = [], $cardData = [])
    {

        $profile->card_brand = strtolower($cardData->getCardBrand());
        $profile->card_last4 = $cardData->getLast4();
        $profile->setProfileData($profileData);

        return $profile;
    }

    /**
     * @param \Admin\Models\Payment_profiles_model $profile
     * @param array $profileData
     * @param array $cardData
     * @return \Admin\Models\Payment_profiles_model
     */
    protected function deletePaymentProfileData($profile)
    {
        $profile->setProfileData([]);

        return $profile;
    }

    //
    //
    //

    /**
     * @return \Square\SquareClient
     */
    protected function createClient()
    {
        $client = new SquareClient([
            'accessToken' => $this->getAccessToken(),
            'environment' => $this->isTestMode() ? Environment::SANDBOX : Environment::PRODUCTION,
        ]);

        return $client;
    }

    protected function getPaymentFormFields($order, $data = [])
    {

        // just for Square - record tips as separate amount
        $order_amt = $order->order_total;
        $tip_amt = 0;
        foreach($order->getOrderTotals() as $ot){
            if($ot->code == 'tip'){
                $tip_amt = $ot->value;
                $order_amt -= $tip_amt;
            }
        }

        $fields = [
            'idempotencyKey' => uniqid(),
            'amount' => number_format($order_amt, 2, '.', ''),
            'currency' => currency()->getUserCurrency(),
            'note' => 'Payment for Order '.$order->order_id,
            'referenceId' => (string)$order->order_id,
        ];

        if($tip_amt){
            $fields['tip'] = number_format($tip_amt, 2, '.', '');
        }

        $this->fireSystemEvent('payregister.square.extendFields', [&$fields, $order, $data]);

        return $fields;
    }

    /**
     * @param \Square\Models\CretePaymentResponse $response
     * @param \Admin\Models\Orders_model $order
     * @param \Admin\Models\Payments_model $host
     * @param $fields
     * @return void
     * @throws \Exception
     */
    protected function handlePaymentResponse($response, $order, $host, $fields, $isRefundable = false)
    {
        if ($response->isSuccess()) {
            $order->logPaymentAttempt('Payment successful', 1, $fields, $response->getResult(), $isRefundable);
            $order->updateOrderStatus($host->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();
        }
        else {
            $errors = $response->getErrors();
            $errors = $errors[0]->getDetail();
            $order->logPaymentAttempt('Payment error -> '.$errors, 0, $fields, $response->getResult());
            throw new Exception($errors);
        }
    }

    protected function handleUpdatePaymentProfile($customer, $data)
    {
        $profile = $this->model->findPaymentProfile($customer);
        $profileData = $profile ? (array)$profile->profile_data : [];

        $response = $this->createOrFetchCustomer($profileData, $customer);

        $customerId = $response->getCustomer()->getId();
        $referenceId = $response->getCustomer()->getReferenceId();

        $response = $this->createOrFetchCard($customerId, $referenceId, $profileData, $data);

        $cardData = $response->getCard();
        $cardId = $response->getCard()->getId();

        if (!$profile)
            $profile = $this->model->initPaymentProfile($customer);

        $this->updatePaymentProfileData($profile, [
            'customer_id' => $customerId,
            'card_id' => $cardId,
        ], $cardData);

        return $profile;
    }

    protected function handleDeletePaymentProfile($customer, $profile)
    {
        if (!isset($profile->profile_data['customer_id']))
            return;

        $cardId = $profile['profile_data']['card_id'];
        $client = $this->createClient();
        $cardsApi = $client->getCardsApi();

        $response = $cardsApi->disableCard($cardId);

        if (!$response->isSuccess()) {
            $errors = $response->getErrors();
            $errors = $errors[0]->getDetail();

            throw new ApplicationException('Square Delete Payment Card Error '.$errors);
        }

        $this->deletePaymentProfileData($profile);

    }
}
