<?php

namespace Igniter\PayRegister\Tests\Classes;

use Igniter\Flame\Exception\ApplicationException;
use Igniter\PayRegister\Classes\AuthorizeNetClient;
use Mockery;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1\CreateTransactionRequest;
use net\authorize\api\contract\v1\MerchantAuthenticationType;
use net\authorize\api\contract\v1\MessagesType;
use net\authorize\api\contract\v1\TransactionResponseType;
use net\authorize\api\controller\CreateTransactionController;

beforeEach(function() {
    $this->authorizeNetClient = new AuthorizeNetClient;
});

it('creates authentication instance', function() {
    $result = $this->authorizeNetClient->authentication();

    expect($result)->toBeInstanceOf(MerchantAuthenticationType::class);
});

it('creates transaction request instance', function() {
    $result = $this->authorizeNetClient->createTransactionRequest();

    expect($result)->toBeInstanceOf(CreateTransactionRequest::class)
        ->and($result->getMerchantAuthentication())->toBeInstanceOf(MerchantAuthenticationType::class);
});

it('returns existing transaction request instance if set', function() {
    $result = $this->authorizeNetClient->createTransactionRequest();

    expect($result)->toBeInstanceOf(CreateTransactionRequest::class);
});

it('throws exception if no response returned from create transaction', function() {
    $request = Mockery::mock(CreateTransactionRequest::class);
    $request->shouldReceive('getMerchantAuthentication')->andReturn(Mockery::mock(MerchantAuthenticationType::class));
    $request->shouldReceive('setClientId')->andReturnSelf();
    $request->shouldReceive('jsonSerialize')->andReturn([]);

    $this->expectException(ApplicationException::class);
    $this->expectExceptionMessage('No response returned');

    $this->authorizeNetClient->createTransaction(new CreateTransactionController($request));
});

it('throws exception if response result code is not Ok', function() {
    $request = Mockery::mock(CreateTransactionRequest::class);
    $request->shouldReceive('getMerchantAuthentication')->andReturn(Mockery::mock(MerchantAuthenticationType::class));
    $request->shouldReceive('setClientId')->andReturnSelf();
    $request->shouldReceive('jsonSerialize')->andReturn([]);

    $response = Mockery::mock(ANetApiResponseType::class);
    $response->shouldReceive('getMessages->getResultCode')->andReturn('Error');

    $transactionResponse = Mockery::mock(TransactionResponseType::class);
    $response->shouldReceive('getTransactionResponse')->andReturn($transactionResponse);

    $this->expectException(ApplicationException::class);

    $this->authorizeNetClient->createTransaction(new CreateTransactionController($request));
});

it('returns transaction response if successful', function() {
    $response = Mockery::mock(ANetApiResponseType::class);
    $response->shouldReceive('getMessages->getResultCode')->andReturn('Ok');

    $request = Mockery::mock(CreateTransactionRequest::class);
    $request->shouldReceive('getMerchantAuthentication')->andReturn(Mockery::mock(MerchantAuthenticationType::class));
    $request->shouldReceive('setClientId')->andReturnSelf();
    $request->shouldReceive('jsonSerialize')->andReturn([$response]);

    $transactionResponse = Mockery::mock(TransactionResponseType::class);
    $transactionResponse->shouldReceive('getMessages')->andReturn(Mockery::mock(MessagesType::class));
    $response->shouldReceive('getTransactionResponse')->andReturn($transactionResponse);

    $controller = Mockery::mock(CreateTransactionController::class);
    $controller->shouldReceive('executeWithApiResponse')->andReturn($response);
    app()->instance(CreateTransactionController::class, $controller);

    $result = $this->authorizeNetClient->createTransaction($controller);

    expect($result)->toBe($transactionResponse);
});
