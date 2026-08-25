<?php

declare(strict_types=1);

namespace CoatiPay;

use GuzzleHttp\Client;
use CoatiPay\Http\ApiRequest;

/**
 * Webhook verification and management.
 *
 * @example
 * $event = $relay->webhooks->verify($payload, $signature, $secret);
 */
class Webhooks
{
    public function __construct(private Client $http) {}

    public function verify(string $payload, string $signature, string $secret): array
    {
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                throw new \InvalidArgumentException('Malformed webhook signature');
            }
            [$k, $v] = $kv;
            $parts[$k] = $v;
        }

        // Validate timestamp freshness (reject if older than 5 minutes)
        $ts = $parts['t'] ?? '';
        if (!is_numeric($ts) || abs(time() - (int)$ts) > 300) {
            throw new \InvalidArgumentException('Webhook timestamp too old or invalid');
        }

        $expected = hash_hmac('sha256', "{$ts}.{$payload}", $secret);
        if (!hash_equals($expected, $parts['v1'] ?? '')) {
            throw new \InvalidArgumentException('Webhook signature verification failed');
        }
        return json_decode($payload, true);
    }

    public function register(string $url, array $events): array
    {
        return ApiRequest::send($this->http, 'POST', '/v1/webhooks', [
            'json' => compact('url', 'events'),
        ]);
    }
}
