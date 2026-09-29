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
        $code = $error['code'] ?? 'unknown_error';
        // La clase más concreta según la categoría del código, como en los SDK
        // de JS y Python (vectores compartidos: errores.json). Un código que
        // este SDK no conoce (una API más nueva) es la clase base.
        $clase = self::CLASES[Catalogo::CATEGORIAS[$code] ?? ''] ?? CoatiPaySDKError::class;
        $texto = static fn (string $clave): ?string => is_string($error[$clave] ?? null) ? $error[$clave] : null;
        return new $clase(
            $code,
            $texto('message') ?? 'Unknown error',
            $texto('param'),
            $texto('doc_url'),
        );
    }

    private const CLASES = [
        'auth' => AuthError::class,
        'validation' => ValidationError::class,
        'routing' => RoutingError::class,
        'payment' => PaymentError::class,
        'rate_limit' => RateLimitError::class,
    ];
}
