<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

use Exception;

/**
 * Error returned by the CoatiPay API.
 *
 * Mirrors the JS SDK's CoatiPaySDKError shape.
 * Note: PHP's Exception already owns the protected int $code property,
 * so the string error code is exposed as $errorCode.
 */
class CoatiPaySDKError extends Exception
{
    public string $errorCode;
    public ?string $param;
    public string $docUrl;

    public function __construct(
        string $code,
        string $message,
        ?string $param = null,
        ?string $docUrl = null
    ) {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->param     = $param;
        // Sin doc_url: la página del código si es del catálogo, o el índice de
        // errores (p. ej. un fallo de red). Antes era https://docs.coatipay.com,
        // que no existe.
        $this->docUrl = $docUrl ?? (isset(Catalogo::CATEGORIAS[$code])
            ? "https://coatipay.com/docs/errors/{$code}"
            : 'https://coatipay.com/docs/errors/');
    }
}
