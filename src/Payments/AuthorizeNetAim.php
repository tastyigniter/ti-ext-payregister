<?php

namespace Igniter\PayRegister\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\AuthorizeNetClient;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithAuthorizedPayment;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1\OpaqueDataType;
use net\authorize\api\contract\v1\OrderType;
use net\authorize\api\contract\v1\PaymentType;
use net\authorize\api\contract\v1\TransactionRequestType;
use net\authorize\api\contract\v1\TransactionResponseType;

class AuthorizeNetAim extends BasePaymentGateway
{
    use WithAuthorizedPayment;
    use WithPaymentRefund;

    public function defineFieldsConfig()
    {
        return 'igniter.payregister::/models/authorizenetaim';
    }

    public function getHiddenFields()
    {
        return [
            'authorizenetaim_DataValue' => '',
            'authorizenetaim_DataDescriptor' => '',
        ];
    }

    public function getClientKey()
    {
        return $this->model->client_key;
    }

    public function getTransactionKey()
    {
        return $this->model->transaction_key;
    }

    public function getApiLoginID()
    {
        return $this->model->api_login_id;
    }

    public function isTestMode()
    {
        return $this->model->transaction_mode != 'live';
    }

    public function shouldAuthorizePayment()
    {
        return $this->model->transaction_type === 'auth_only';
    }

    public function getEndPoint()
    {
        return $this->isTestMode() ? 'https://jstest.authorize.net' : 'https://js.authorize.net';
    }

    public function getAcceptedCards()
    {
        return [
            'visa' => 'lang:igniter.payregister::default.authorize_net_aim.text_visa',
            'mastercard' => 'lang:igniter.payregister::default.authorize_net_aim.text_mastercard',
            'american_express' => 'lang:igniter.payregister::default.authorize_net_aim.text_american_express',
            'jcb' => 'lang:igniter.payregister::default.authorize_net_aim.text_jcb',
            'diners_club' => 'lang:igniter.payregister::default.authorize_net_aim.text_diners_club',
        ];
    }

    /**
     * @param self $host
     * @param \Igniter\Main\Classes\MainController $controller
     */
    public function beforeRenderPaymentForm($host, $controller)
    {
        $controller->addJs('igniter.payregister::/js/authorizenetaim.js', 'authorizenetaim-js');
    }

    /**
     * @param array $data
     * @param \Igniter\PayRegister\Models\Payment $host
     * @param \Igniter\Cart\Models\Order $order
     *
     * @return mixed
     */
    public function processPaymentForm($data, $host, $order)
    {
        $this->validateApplicableFee($order, $host);

        $fields = $this->getPaymentFormFields($order, $data);
        $fields['opaqueDataDescriptor'] = array_get($data, 'authorizenetaim_DataDescriptor');
        $fields['opaqueDataValue'] = array_get($data, 'authorizenetaim_DataValue');

        try {
            $response = $this->createAcceptPayment($fields, $order);
            $responseData = $this->convertResponseToArray($response);

            if ($response->getResponseCode() == '1') {
                $order->logPaymentAttempt('Payment successful', 1, $fields, $responseData, !$this->shouldAuthorizePayment());
                $order->updateOrderStatus($host->order_status, ['notify' => false]);
                $order->markAsPaymentProcessed();

                return;
            }

            $order->logPaymentAttempt('Payment unsuccessful -> '.$responseData['description'], 0, $fields, $responseData);
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Payment error -> '.$ex->getMessage(), 0, $fields);
        }

        throw new ApplicationException('Sorry, there was an error processing your payment. Please try again later.');
    }

    public function processRefundForm($data, $order, $paymentLog)
    {
        throw_if(
            !is_null($paymentLog->refunded_at) || !is_array($paymentLog->response),
            new ApplicationException('Nothing to refund')
        );

        throw_if(
            array_get($paymentLog->response, 'status') !== '1',
            new ApplicationException('No successful transaction to refund')
        );

        $paymentId = array_get($paymentLog->response, 'id');
        $fields = $this->getPaymentRefundFields($order, $data);

        throw_if(
            $fields['amount']['value'] > $order->order_total,
            new ApplicationException('Refund amount should be be less than or equal to the order total')
        );

        try {
            $response = $this->createRefundPayment($fields, $order);
            $responseData = $this->convertResponseToArray($response);

            $order->logPaymentAttempt(sprintf('Payment %s refund processed -> (%s: %s)',
                $paymentId, currency_format($fields['amount']), $responseData['id']
            ), 1, $fields, $responseData);

            $paymentLog->markAsRefundProcessed();
        } catch (Exception $ex) {
            $order->logPaymentAttempt('Refund failed -> '.$ex->getMessage(), 0, $fields, []);

            throw new Exception('Refund failed');
        }
    }

    public function captureAuthorizedPayment(Order $order)
    {
        throw_unless(
            $paymentLog = $order->payment_logs()->firstWhere('is_success', true),
            new ApplicationException('No successful transaction to capture')
        );

        throw_unless(
            $paymentId = array_get($paymentLog->response, 'id'),
            new ApplicationException('Missing payment ID in successful transaction response')
        );

        $transactionRequestType = new TransactionRequestType();
        $transactionRequestType->setTransactionType('priorAuthCaptureTransaction');
        $transactionRequestType->setRefTransId($paymentId);

        $client = $this->createClient();

        $request = $client->createTransactionRequest();
        $request->setRefId($order->hash);
        $request->setTransactionRequest($transactionRequestType);

        $this->fireSystemEvent('payregister.paypalexpress.extendCaptureRequest', [$request], false);

        $response = $client->createTransaction($request);
        $responseData = $this->convertResponseToArray($response);

        if ($response->getResponseCode() == '1') {
            $order->logPaymentAttempt('Payment captured successfully', 1, [], $responseData, true);
        } else {
            $order->logPaymentAttempt('Payment captured failed', 0, [], $responseData);
        }

        return $response;
    }

    //
    //
    //

    protected function createClient()
    {
        $client = new AuthorizeNetClient($this->isTestMode());

        $merchantAuthentication = $client->authentication();
        $merchantAuthentication->setName($this->getApiLoginID());
        $merchantAuthentication->setTransactionKey($this->getTransactionKey());

        $this->fireSystemEvent('payregister.authorizenetaim.extendGateway', [$client]);

        return $client;
    }

    protected function getPaymentFormFields($order, $data = [])
    {
        return [
            'amount' => number_format($order->order_total, 2, '.', ''),
            'transactionId' => $order->order_id,
            'currency' => currency()->getUserCurrency(),
        ];
    }

    protected function getPaymentRefundFields($order, $data)
    {
        $refundAmount = array_get($data, 'refund_type') == 'full'
            ? $order->order_total : array_get($data, 'refund_amount');

        return [
            'transactionId' => $order->getKey(),
            'amount' => number_format($refundAmount, 2, '.', ''),
        ];
    }

    protected function createAcceptPayment(mixed $fields, Order $order)
    {
        $opaqueData = new OpaqueDataType();
        $opaqueData->setDataDescriptor($fields['opaqueDataDescriptor']);
        $opaqueData->setDataValue($fields['opaqueDataValue']);

        $transactionRequestType = new TransactionRequestType();
        $transactionRequestType->setTransactionType(
            $this->shouldAuthorizePayment() ? 'authOnlyTransaction' : 'authCaptureTransaction'
        );
        $transactionRequestType->setAmount($fields['amount']);

        $paymentOne = new PaymentType();
        $paymentOne->setOpaqueData($opaqueData);
        $transactionRequestType->setPayment($paymentOne);

        $orderType = new OrderType();
        $orderType->setInvoiceNumber($fields['transactionId']);
        $transactionRequestType->setOrder($orderType);

        $client = $this->createClient();

        $request = $client->createTransactionRequest();
        $request->setRefId($order->hash);
        $request->setTransactionRequest($transactionRequestType);

        $this->fireSystemEvent('payregister.paypalexpress.extendAcceptRequest', [$request], false);

        return $client->createTransaction($request);
    }

    protected function createCapturePayment()
    {

    }

    protected function createRefundPayment(mixed $fields, Order $order)
    {
        $transactionRequestType = new TransactionRequestType();
        $transactionRequestType->setTransactionType('refundTransaction');
        $transactionRequestType->setAmount($fields['amount']);
        $transactionRequestType->setRefTransId($fields['transactionId']);

        $client = $this->createClient();

        $request = $client->createTransactionRequest();
        $request->setRefId($order->hash);
        $request->setTransactionRequest($transactionRequestType);

        $this->fireSystemEvent('payregister.paypalexpress.extendRefundRequest', [$request], false);

        return $client->createTransaction($request);
    }

    protected function getErrorMessageFromResponse(?AnetApiResponseType $response, ?TransactionResponseType $transactionResponse): string
    {
        $message = "Transaction Failed \n Error Code  : %s \n Error Message : %s \n";
        if ($transactionResponse != null && $transactionResponse->getErrors() != null) {
            return sprintf($message,
                $transactionResponse->getErrors()[0]->getErrorCode(),
                $transactionResponse->getErrors()[0]->getErrorText()
            );
        }

        return sprintf($message,
            $response->getMessages()->getMessage()[0]->getCode(),
            $response->getMessages()->getMessage()[0]->getText()
        );
    }

    protected function convertResponseToArray(TransactionResponseType $response): array
    {
        return [
            'id' => $response->getTransId(),
            'status' => $response->getResponseCode(),
            'code' => $response->getMessages()[0]->getCode(),
            'description' => $response->getMessages()[0]->getDescription(),
            'card_holder' => $response->getAccountNumber(),
            'card_type' => $response->getAccountType(),
            'auth_code' => $response->getAuthCode(),
        ];
    }
}
