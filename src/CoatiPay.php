<?php

declare(strict_types=1);

namespace CoatiPay;

use GuzzleHttp\Client;
use CoatiPay\Errors\CoatiPaySDKError;
use CoatiPay\Http\ApiRequest;
use CoatiPay\X402\X402Middleware;

/**
 * CoatiPay PHP SDK client.
 *
 * @example
 * $relay = new CoatiPay('sk_live_xxx');
 * $intent = $relay->paymentIntents->create(10_000_000, 'usdc', 'base');
 */
class CoatiPay
{
    private Client $http;
    public ?string $merchantWallet;
    public PaymentIntents $paymentIntents;
    public Webhooks $webhooks;
    public X402Middleware $x402;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.coatipay.com',
        float $timeout = 30.0,
        ?string $merchantWallet = null,
    ) {
        if ($apiKey === '') {
            throw new CoatiPaySDKError('api_key_required', 'CoatiPay: apiKey is required');
        }

        $this->merchantWallet = $merchantWallet;
        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout'  => $timeout,
            'headers'  => [
                'Authorization'     => "Bearer {$apiKey}",
                'Content-Type'      => 'application/json',
                'CoatiPay-Version' => '0.1',
            ],
        ]);
        $this->paymentIntents = new PaymentIntents($this->http);
        $this->webhooks       = new Webhooks($this->http);
        $this->x402           = new X402Middleware($this);
    }

    /**
     * Verify an x402 payment header against the CoatiPay API.
     *
     * @param array<string, mixed> $options
     */
    public function x402Verify(string $paymentHeader, int $amount, string $chain, array $options = []): bool
    {
        try {
            $result = ApiRequest::send($this->http, 'POST', '/v1/x402/verify', [
                'json' => [
                    'payment' => $paymentHeader,
                    'amount'  => $amount,
                    'chain'   => $chain,
                ] + $options,
            ]);
            // Require an explicit verified=true (not just a 2xx) so a future API
            // response without verification can't slip a request through.
            return ($result['verified'] ?? false) === true;
        } catch (CoatiPaySDKError) {
            return false;
        }
    }
}
