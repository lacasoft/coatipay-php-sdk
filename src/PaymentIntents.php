<?php

declare(strict_types=1);

namespace CoatiPay;

use GuzzleHttp\Client;
use CoatiPay\Http\ApiRequest;

/**
 * Operations on payment intents.
 *
 * @example
 * $intent = $relay->paymentIntents->create(10_000_000, 'usdc', 'base', ['order_id' => '123']);
 */
class PaymentIntents
{
    /**
     * La forma del id de un cobro que da la API: `pi_` y después letras ASCII,
     * dígitos, `_` o `-`, y nada más. La misma regla en los tres SDK (vectores
     * compartidos, `ids.json`). Se usa con el modificador `D`: sin él, `$`
     * acepta un salto de línea final.
     */
    public const PAYMENT_INTENT_ID_PATTERN = '^pi_[A-Za-z0-9_-]+$';

    public function __construct(private Client $http) {}

    /** ¿Tiene `$value` la forma de un id de cobro (`pi_…`)? */
    public static function isPaymentIntentId(mixed $value): bool
    {
        return is_string($value) && preg_match('/' . self::PAYMENT_INTENT_ID_PATTERN . '/D', $value) === 1;
    }

    /**
     * La ruta de un cobro. El id va dentro de la ruta: con `/`, `?` o `..`, la
     * petición, con la clave del comercio, llegaría a otra ruta de la API. Se
     * comprueba su forma antes de hacer la petición, y se codifica.
     */
    private static function rutaDelCobro(string $intentId): string
    {
        if (!self::isPaymentIntentId($intentId)) {
            throw new \InvalidArgumentException(
                'Invalid payment intent id (expected pi_…): '
                . json_encode(substr($intentId, 0, 64), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            );
        }
        return '/v1/payment_intents/' . rawurlencode($intentId);
    }

    /**
     * Create a payment intent.
     *
     * `$idempotencyKey` makes it safe to retry: the same key with the same
     * parameters returns the same intent instead of creating another one (and
     * other parameters with the same key are an `idempotency_key_reused` error).
     */
    public function create(
        int $amount,
        string $currency,
        string $chain,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): array {
        return ApiRequest::send($this->http, 'POST', '/v1/payment_intents', [
            'json' => compact('amount', 'currency', 'chain', 'metadata'),
        ] + ($idempotencyKey !== null && $idempotencyKey !== '' ? ['headers' => ['Idempotency-Key' => $idempotencyKey]] : []));
    }

    /**
     * @throws \InvalidArgumentException if `$intentId` is not a payment intent id (`pi_…`); no request is made.
     */
    public function retrieve(string $intentId): array
    {
        return ApiRequest::send($this->http, 'GET', self::rutaDelCobro($intentId));
    }

    /**
     * @throws \InvalidArgumentException if `$intentId` is not a payment intent id (`pi_…`); no request is made.
     */
    public function cancel(string $intentId): array
    {
        return ApiRequest::send($this->http, 'POST', self::rutaDelCobro($intentId) . '/cancel');
    }

    public function list(int $limit = 10, ?string $startingAfter = null): array
    {
        $query = ['limit' => $limit];
        if ($startingAfter) {
            $query['starting_after'] = $startingAfter;
        }
        return ApiRequest::send($this->http, 'GET', '/v1/payment_intents', [
            'query' => $query,
        ]);
    }

    // ── Gasless settlement (ERC-3009 / ADR-003) ─────────────────────────

    /**
     * Construye el typed data EIP-712 de USDC `ReceiveWithAuthorization`.
     *
     * El nonce del mensaje sale del `$intentId`, no de un aleatorio: el contrato
     * exige que coincida con el intent para que el nodeit no pueda redirigir la
     * firma a otro. Se pasa el id textual y la derivación a bytes32 ocurre
     * dentro, para que nadie tenga que calcular el keccak a mano y equivocarse.
     *
     * @param string $intentId Identificador del intent tal cual lo devuelve la API (`pi_…`).
     * @param string $merchant La cartera del comercio: el `merchant_wallet` del PaymentIntent.
     *                         Entra en el nonce (ADR-009).
     * @param array<string, mixed> $options `validAfter` / `validBefore`, en segundos Unix.
     * @return array<string, mixed>
     */
    public function buildAuthorizationTypedData(
        string $payer,
        int $amount,
        string $settlementHub,
        string $chain,
        string $intentId,
        string $merchant,
        array $options = [],
    ): array {
        return Eip712::buildAuthorizationTypedData(
            $payer,
            $amount,
            $settlementHub,
            $chain,
            $intentId,
            $merchant,
            $options,
        );
    }

    /**
     * Construye y firma un mensaje `ReceiveWithAuthorization`.
     *
     * `$intentId` es obligatorio: es el que ata la firma a un único intent.
     *
     * @param string $intentId Identificador del intent tal cual lo devuelve la API (`pi_…`);
     *                         el bytes32 del nonce se deriva dentro.
     * @param string $merchant La cartera del comercio: el `merchant_wallet` del PaymentIntent.
     *                         Entra en el nonce (ADR-009): la firma solo paga a esa cartera.
     * @param array<string, mixed> $options `validAfter` / `validBefore`, en segundos Unix.
     */
    public function signAuthorization(
        string $payer,
        int $amount,
        string $settlementHub,
        string $chain,
        string $intentId,
        string $merchant,
        string $privateKey,
        array $options = [],
    ): SignedAuthorization {
        return Eip712::signAuthorization(
            $payer,
            $amount,
            $settlementHub,
            $chain,
            $intentId,
            $merchant,
            $privateKey,
            $options,
        );
    }

    /**
     * @throws \InvalidArgumentException if `$intentId` is not a payment intent id (`pi_…`); no request is made.
     */
    public function submitAuthorization(string $intentId, SignedAuthorization $authorization): array
    {
        return ApiRequest::send(
            $this->http,
            'POST',
            self::rutaDelCobro($intentId) . '/authorize',
            ['json' => Eip712::serializeAuthorization($authorization)],
        );
    }

    /**
     * @param array<int, array{intent_id: string, authorization: SignedAuthorization}> $items
     */
    public function submitAuthorizationBatch(array $items): array
    {
        if (count($items) === 0) {
            return ['results' => [], 'queued' => 0, 'rejected' => 0];
        }
        if (count($items) > Eip712::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(
                'Batch too large: ' . count($items) . ' authorizations (max ' . Eip712::MAX_BATCH_SIZE . ')'
            );
        }
        return ApiRequest::send(
            $this->http,
            'POST',
            '/v1/payment_intents/batch/authorize',
            [
                'json' => [
                    'items' => array_map(
                        fn (array $item) => [
                            'intent_id' => $item['intent_id'],
                            'authorization' => Eip712::serializeAuthorization($item['authorization']),
                        ],
                        $items
                    ),
                ],
            ],
        );
    }
}
