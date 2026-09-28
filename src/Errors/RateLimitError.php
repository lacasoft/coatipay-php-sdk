<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

/** Too many requests. Wait before retrying (the response carries Retry-After). */
class RateLimitError extends CoatiPaySDKError
{
}
