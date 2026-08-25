<?php

declare(strict_types=1);

namespace CoatiPay;

/**
 * A signed ERC-3009 ReceiveWithAuthorization message, ready to submit.
 */
class SignedAuthorization
{
    public function __construct(
        public string $payer,
        public int $validAfter,
        public int $validBefore,
        public string $nonce,
        public string $signature,
    ) {}
}
