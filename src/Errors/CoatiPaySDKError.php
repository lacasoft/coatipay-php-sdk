<?php

declare(strict_types=1);

namespace CoatiPay\Errors;

use Exception;

/**
 * Everything an API call throws: the API's errors, classified by category,
 * and NetworkError when there was no CoatiPay answer. One `catch` covers them
 * all, as in the JS and Python SDKs.
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
        ?string $docUrl = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
        $this->param     = $param;
        // Sin doc_url: la página del código si es del catálogo, o el índice de
        // errores (un código que pone el SDK sin página propia, como
        // api_key_required). Antes era https://docs.coatipay.com, que no existe.
        $this->docUrl = $docUrl ?? (isset(Catalogo::CATEGORIAS[$code])
            ? "https://coatipay.com/docs/errors/{$code}"
            : 'https://coatipay.com/docs/errors/');
    }
}
