<?php

declare(strict_types=1);

namespace CoatiPay;

use GuzzleHttp\Client;
use CoatiPay\Http\ApiRequest;

/**
 * Operations on payment intents.
 *
 * @example
 * $intent = $relay->paymentIntents->create(10_000_000, 'usdc', 'base', ['order_id' => '123']);
 */
class PaymentIntents
{
    public function __construct(private Client $http) {}

    public function create(int $amount, string $currency, string $chain, array $metadata = []): array
    {
        return ApiRequest::send($this->http, 'POST', '/v1/payment_intents', [
            'json' => compact('amount', 'currency', 'chain', 'metadata'),
        ]);
    }

    public function retrieve(string $intentId): array
    {
        return ApiRequest::send($this->http, 'GET', "/v1/payment_intents/{$intentId}");
    }

    public function cancel(string $intentId): array
    {
        return ApiRequest::send($this->http, 'POST', "/v1/payment_intents/{$intentId}/cancel");
    }

    public function list(int $limit = 10, ?string $startingAfter = null): array
    {
        $query = ['limit' => $limit];
        if ($startingAfter) {
            $query['starting_after'] = $startingAfter;
        }
        return ApiRequest::send($this->http, 'GET', '/v1/payment_intents', [
            'query' => $query,
        ]);
    }

    // ── Gasless settlement (ERC-3009 / ADR-003) ─────────────────────────

    /**
     * Build the EIP-712 typed data for USDC ReceiveWithAuthorization.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function buildAuthorizationTypedData(
        string $payer,
        int $amount,
        string $settlementHub,
        string $chain,
        array $options = [],
    ): array {
        return Eip712::buildAuthorizationTypedData($payer, $amount, $settlementHub, $chain, $options);
    }

    /**
     * Build and sign a ReceiveWithAuthorization message.
     *
     * @param array<string, mixed> $options
     */
    public function signAuthorization(
        string $payer,
        int $amount,
        string $settlementHub,
        string $chain,
        string $privateKey,
        array $options = [],
    ): SignedAuthorization {
        return Eip712::signAuthorization($payer, $amount, $settlementHub, $chain, $privateKey, $options);
    }

    public function submitAuthorization(string $intentId, SignedAuthorization $authorization): array
    {
        return ApiRequest::send(
            $this->http,
            'POST',
            "/v1/payment_intents/{$intentId}/authorize",
            ['json' => Eip712::serializeAuthorization($authorization)],
        );
    }

    /**
     * @param array<int, array{intent_id: string, authorization: SignedAuthorization}> $items
     */
    public function submitAuthorizationBatch(array $items): array
    {
        if (count($items) === 0) {
            return ['results' => [], 'queued' => 0, 'rejected' => 0];
        }
        if (count($items) > Eip712::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(
                'Batch too large: ' . count($items) . ' authorizations (max ' . Eip712::MAX_BATCH_SIZE . ')'
            );
        }
        return ApiRequest::send(
            $this->http,
            'POST',
            '/v1/payment_intents/batch/authorize',
            [
                'json' => [
                    'items' => array_map(
                        fn (array $item) => [
                            'intent_id' => $item['intent_id'],
                            'authorization' => Eip712::serializeAuthorization($item['authorization']),
                        ],
                        $items
                    ),
                ],
            ],
        );
    }
}
