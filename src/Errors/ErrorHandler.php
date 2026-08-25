<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

class ErrorHandler
{
    /**
     * Convert an API error payload into an CoatiPaySDKError.
     *
     * @param array<string, mixed> $error
     */
    public static function classify(array $error): CoatiPaySDKError
    {
        return new CoatiPaySDKError(
            $error['code'] ?? 'unknown_error',
            $error['message'] ?? 'Unknown error',
            $error['param'] ?? null,
            $error['doc_url'] ?? 'https://docs.coatipay.com'
        );
    }
}
