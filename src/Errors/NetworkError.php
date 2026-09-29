<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

/**
 * The request got no CoatiPay answer: no response at all (network, DNS,
 * timeout: `status` is null), or a response that is not a CoatiPay error (a
 * proxy's HTML 502, a body that is not JSON: `status` is its HTTP status).
 *
 * Whether the request took effect is unknown: before retrying a write, check
 * (or create with the same `idempotency_key`). Code `network_error`, set by the
 * SDK, never sent by the API. Same rule as the JS and Python SDKs (shared
 * vectors: `errores.json`, `respuestas`). The original exception, if any, is
 * `getPrevious()`.
 */
class NetworkError extends CoatiPaySDKError
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            'network_error',
            $message,
            null,
            'https://coatipay.com/docs/errors/network_error',
            $previous,
        );
    }
}
