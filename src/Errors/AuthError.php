<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

/** API key missing, revoked, or lacking permissions; or an invalid session or token. */
class AuthError extends CoatiPaySDKError
{
}
