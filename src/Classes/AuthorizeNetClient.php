<?php

namespace Igniter\PayRegister\Classes;

use Igniter\Flame\Exception\ApplicationException;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1\CreateTransactionRequest;
use net\authorize\api\contract\v1\MerchantAuthenticationType;
use net\authorize\api\contract\v1\TransactionResponseType;
use net\authorize\api\controller\CreateTransactionController;

class AuthorizeNetClient
{
    protected ?MerchantAuthenticationType $authentication = null;

    protected ?CreateTransactionRequest $transactionRequest = null;

    public function __construct(protected bool $sandbox = false) {}

    public function authentication()
    {
        if ($this->authentication) {
            return $this->authentication;
        }

        return $this->authentication = new MerchantAuthenticationType();
    }

    public function createTransactionRequest(): CreateTransactionRequest
    {
        if ($this->transactionRequest) {
            return $this->transactionRequest;
        }

        $request = new CreateTransactionRequest();
        $request->setMerchantAuthentication($this->authentication());

        return $this->transactionRequest = $request;
    }

    public function createTransaction(?CreateTransactionRequest $request): TransactionResponseType
    {
        $controller = new CreateTransactionController($request);

        $response = $controller->executeWithApiResponse($this->sandbox
            ? \net\authorize\api\constants\ANetEnvironment::SANDBOX
            : \net\authorize\api\constants\ANetEnvironment::PRODUCTION);

        throw_if(is_null($response), new ApplicationException('No response returned'));

        $transactionResponse = $response->getTransactionResponse();

        throw_unless(
            $response->getMessages()->getResultCode() == 'Ok',
            new ApplicationException($this->getErrorMessageFromResponse($response, $transactionResponse))
        );

        throw_if(
            is_null($transactionResponse) || is_null($transactionResponse->getMessages()),
            new ApplicationException($this->getErrorMessageFromResponse($response, $transactionResponse))
        );

        return $transactionResponse;
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
}
