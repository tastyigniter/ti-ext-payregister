<?php

namespace Igniter\PayRegister\Classes;

use Igniter\Flame\Exception\ApplicationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayPalClient
{
    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected bool $sandbox
    ) {
    }

    public function getOrder($orderId)
    {
        return Http::asJson()->withHeaders([
            'Authorization' => $this->generateAccessToken(),
        ])->get($this->endpoint('v2/checkout/orders/'.$orderId));
    }

    public function createOrder($params)
    {
        return Http::asJson()
            ->withHeaders($this->prepareHeaders())
            ->post($this->endpoint('v2/checkout/orders'), $params);
    }

    public function captureOrder($orderId)
    {
        return Http::contentType('application/json')
            ->withHeaders($this->prepareHeaders())
            ->send('POST', $this->endpoint('v2/checkout/orders/'.$orderId.'/capture'));
    }

    public function authorizeOrder($orderId)
    {
        return Http::contentType('application/json')
            ->withHeaders($this->prepareHeaders())
            ->send('POST', $this->endpoint('v2/checkout/orders/'.$orderId.'/authorize'));
    }

    public function getPayment($id)
    {
        return Http::asJson()->withHeaders([
            'Authorization' => $this->generateAccessToken(),
        ])->get($this->endpoint('v1/payments/capture/'.$id));
    }

    public function refundPayment($id, $params)
    {
        return Http::asJson()
            ->withHeaders($this->prepareHeaders())
            ->post($this->endpoint('v2/payments/captures/'.$id.'/refund'), $params);
    }

    protected function generateAccessToken()
    {
        if (!cache()->has('payregister_paypal_access_token')) {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post($this->endpoint('v1/oauth2/token'), [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->json('access_token')) {
                throw new ApplicationException('Failed to generate access token');
            }

            cache()->put('payregister_paypal_access_token', $response->json('access_token'), $response->json('expires_in') - 60);
        }

        return 'Bearer '.cache()->get('payregister_paypal_access_token');
    }

    protected function endpoint(string $uri)
    {
        $endpoint = app()->environment('production')
            ? 'https://api-m.paypal.com/'
            : 'https://api-m.sandbox.paypal.com/';

        return $endpoint.$uri;
    }

    protected function prepareHeaders(): array
    {
        return [
            'Authorization' => $this->generateAccessToken(),
            'PayPal-Request-Id' => Str::uuid()->toString(),
        ];
    }

    public function setTestMode(bool $isSandboxMode)
    {
        $this->config['sandbox'] = $isSandboxMode;

        return $this;
    }

    public function setBrandName(mixed $setting)
    {
    }
}
