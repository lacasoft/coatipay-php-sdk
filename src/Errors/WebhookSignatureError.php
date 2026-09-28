<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

/**
 * Thrown by `webhooks->verify()` when the request cannot be trusted.
 *
 * An `InvalidArgumentException`, as the SDK threw before, now with a `reason`:
 * `malformed_header`, `timestamp_out_of_tolerance` or `no_matching_signature`.
 */
class WebhookSignatureError extends \InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
