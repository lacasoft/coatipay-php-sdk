<?php

declare(strict_types=1);

namespace CoatiPay;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * EIP-712 / ERC-3009 helpers for gasless USDC settlement on Base.
 */
class Eip712
{
    public const DEFAULT_VALIDITY_WINDOW_SECONDS = 30 * 60;
    public const MAX_BATCH_SIZE = 50;

    private const USDC_ADDRESSES = [
        'base' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'base-sepolia' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
    ];

    private const CHAIN_IDS = [
        'base' => 8453,
        'base-sepolia' => 84532,
    ];

    private const USDC_DOMAIN_NAMES = [
        'base' => 'USD Coin',
        'base-sepolia' => 'USDC',
    ];

    private const USDC_DOMAIN_VERSION = '2';

    private const EIP712DOMAIN_TYPEHASH = '8b73c3c69bb8fe3d512ecc4cf759cc79239f7b179b0ffacaa9a75d522b39400f';
    private const RECEIVE_WITH_AUTHORIZATION_TYPEHASH = 'd099cc98ef71107a616c4f0f941f04c322d8e254fe26b3c6668db87aae413de8';

    /**
     * Identificador on-chain de un intent, a partir del `pi_…` que devuelve la API.
     *
     * Es `keccak256(utf8(id))`, la misma derivación que usan el contrato, el
     * daemon y los SDK de JavaScript y Python. Se expone porque quien construya
     * la autorización a mano necesita exactamente este valor, y calcularlo de
     * otra forma produce una firma atada a un intent que no existe.
     *
     * El id NO se normaliza (ni trim ni minúsculas): cualquier retoque cambiaría
     * el hash y dejaría de coincidir con el id que guarda la API.
     */
    public static function intentIdToBytes32(string $intentId): string
    {
        if ($intentId === '') {
            throw new \InvalidArgumentException('intentId is required');
        }
        // Salvavidas de migración: hasta la versión anterior este SDK pedía el
        // bytes32 ya derivado. Si alguien sigue pasándolo, hashearlo otra vez
        // daría un nonce silenciosamente equivocado, y el fallo solo aparecería
        // al liquidar. Un id de la API es `pi_…`, nunca 0x + 64 hex.
        if (preg_match('/^0x[a-fA-F0-9]{64}$/', $intentId)) {
            throw new \InvalidArgumentException(
                "Invalid intent id: {$intentId} (expected the API id, e.g. \"pi_abc123\", not a bytes32)"
            );
        }
        // Sobre los bytes utf-8 del id, siempre. No se usa el helper interno
        // `keccak256()` porque ese trata un `0x…` como hexadecimal, y aquí el
        // id es texto pase lo que pase.
        return '0x' . Keccak::hash($intentId, 256);
    }

    /**
     * Construye el typed data EIP-712 de USDC `ReceiveWithAuthorization`.
     *
     * El nonce ya no se elige: ES el intent. El contrato exige que el nonce
     * coincida con el `intentId` on-chain —el bytes32 que se deriva aquí del
     * `pi_…`—, y esa atadura es justo lo que impide que el nodeit
     * —la parte no confiable, la que envía la transacción— aplique la firma del
     * pagador a OTRO intent y se quede el pago.
     *
     * @param string $intentId Identificador del intent tal cual lo devuelve la API (`pi_…`);
     *                         el bytes32 del nonce se deriva aquí dentro.
     * @param array<string, mixed> $options `validAfter` / `validBefore`, en segundos Unix.
     * @return array<string, mixed>
     */
    public static function buildAuthorizationTypedData(
        string $payer,
        int $amount,
        string $settlementHub,
        string $chain,
        string $intentId,
        array $options = [],
    ): array {
        $nowSeconds = time();
        $validAfter = $options['validAfter'] ?? 0;
        $validBefore = $options['validBefore'] ?? ($nowSeconds + self::DEFAULT_VALIDITY_WINDOW_SECONDS);
        // El nonce ES el intent: así la firma solo sirve para pagar ese intent.
        // Se deriva aquí para que nadie tenga que calcular el keccak por su cuenta.
        $nonce = self::intentIdToBytes32($intentId);

        return [
            'domain' => [
                'name' => self::USDC_DOMAIN_NAMES[$chain] ?? self::USDC_DOMAIN_NAMES['base'],
                'version' => self::USDC_DOMAIN_VERSION,
                'chainId' => self::CHAIN_IDS[$chain] ?? self::CHAIN_IDS['base'],
                'verifyingContract' => self::USDC_ADDRESSES[$chain] ?? self::USDC_ADDRESSES['base'],
            ],
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'ReceiveWithAuthorization' => [
                    ['name' => 'from', 'type' => 'address'],
                    ['name' => 'to', 'type' => 'address'],
                    ['name' => 'value', 'type' => 'uint256'],
                    ['name' => 'validAfter', 'type' => 'uint256'],
                    ['name' => 'validBefore', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'bytes32'],
                ],
            ],
            'primaryType' => 'ReceiveWithAuthorization',
            'message' => [
                'from' => self::normalizeAddress($payer),
                'to' => self::normalizeAddress($settlementHub),
                'value' => $amount,
                'validAfter' => $validAfter,
                'validBefore' => $validBefore,
                'nonce' => $nonce,
            ],
        ];
    }

    /**
     * Compute the EIP-712 digest for the given typed data.
     *
     * @param array<string, mixed> $typedData
     */
    public static function hashTypedData(array $typedData): string
    {
        $domain = $typedData['domain'];
        $message = $typedData['message'];

        $domainSeparator = self::keccak256(
            self::encodeAbi(
                ['bytes32', 'bytes32', 'bytes32', 'uint256', 'address'],
                [
                    '0x' . self::EIP712DOMAIN_TYPEHASH,
                    self::keccak256($domain['name']),
                    self::keccak256($domain['version']),
                    $domain['chainId'],
                    $domain['verifyingContract'],
                ]
            )
        );

        $structHash = self::keccak256(
            self::encodeAbi(
                ['bytes32', 'address', 'address', 'uint256', 'uint256', 'uint256', 'bytes32'],
                [
                    '0x' . self::RECEIVE_WITH_AUTHORIZATION_TYPEHASH,
                    $message['from'],
                    $message['to'],
                    $message['value'],
                    $message['validAfter'],
                    $message['validBefore'],
                    $message['nonce'],
                ]
            )
        );

        return self::keccak256('0x1901' . substr($domainSeparator, 2) . substr($structHash, 2));
    }

    /**
     * Construye y firma un mensaje `ReceiveWithAuthorization`.
     *
     * `$intentId` es obligatorio porque el nonce se deriva de él: sin esa
     * atadura, la firma resultante podría reutilizarse para liquidar un intent
     * distinto del que autorizó el pagador.
     *
     * @param string $intentId Identificador del intent tal cual lo devuelve la API (`pi_…`);
     *                         el bytes32 del nonce se deriva aquí dentro.
     * @param array<string, mixed> $options `validAfter` / `validBefore`, en segundos Unix.
     */
    public static function signAuthorization(
        string $payer,
        int $amount,
        string $settlementHub,
        string $chain,
        string $intentId,
        string $privateKey,
        array $options = [],
    ): SignedAuthorization {
        $typedData = self::buildAuthorizationTypedData($payer, $amount, $settlementHub, $chain, $intentId, $options);
        $digest = self::hashTypedData($typedData);

        $privateKey = str_starts_with($privateKey, '0x') ? substr($privateKey, 2) : $privateKey;

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKey, 'hex');
        // `canonical` forces low-s (s <= n/2). USDC's OpenZeppelin ECDSA rejects
        // high-s signatures (EIP-2), so without this ~50% of signatures revert
        // on-chain. elliptic-php also flips recoveryParam, so v stays correct.
        $sig = $key->sign(substr($digest, 2), 'hex', ['canonical' => true]);

        $v = ($sig->recoveryParam ?? 0) + 27;
        $r = str_pad($sig->r->toString('hex'), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString('hex'), 64, '0', STR_PAD_LEFT);
        $signature = '0x' . $r . $s . str_pad(dechex($v), 2, '0', STR_PAD_LEFT);

        return new SignedAuthorization(
            payer: $typedData['message']['from'],
            validAfter: $typedData['message']['validAfter'],
            validBefore: $typedData['message']['validBefore'],
            nonce: $typedData['message']['nonce'],
            signature: $signature,
        );
    }

    /**
     * Split a 65-byte hex signature into {v, r, s}.
     *
     * @return array<string, string>
     */
    public static function splitSignature(string $signature): array
    {
        $hex = str_starts_with($signature, '0x') ? substr($signature, 2) : $signature;
        if (strlen($hex) !== 130) {
            throw new \InvalidArgumentException('Invalid signature length: expected 65 bytes');
        }

        $r = '0x' . substr($hex, 0, 64);
        $s = '0x' . substr($hex, 64, 64);
        $v = hexdec(substr($hex, 128, 2));
        if ($v < 27) {
            $v += 27;
        }

        return ['v' => (string) $v, 'r' => $r, 's' => $s];
    }

    /**
     * Convert a SignedAuthorization to the wire format expected by the API.
     *
     * @return array<string, mixed>
     */
    public static function serializeAuthorization(SignedAuthorization $auth): array
    {
        return [
            'payer' => $auth->payer,
            'valid_after' => (string) $auth->validAfter,
            'valid_before' => (string) $auth->validBefore,
            'nonce' => $auth->nonce,
            'signature' => $auth->signature,
        ];
    }

    /** USDC contract address for the given chain (falls back to Base mainnet). */
    public static function usdcAddress(string $chain): string
    {
        return self::USDC_ADDRESSES[$chain] ?? self::USDC_ADDRESSES['base'];
    }

    /** USDC EIP-712 domain `name` for the given chain (Base mainnet → "USD Coin"). */
    public static function usdcDomainName(string $chain): string
    {
        return self::USDC_DOMAIN_NAMES[$chain] ?? self::USDC_DOMAIN_NAMES['base'];
    }

    /** USDC EIP-712 domain `version` ("2" across both Base chains). */
    public static function usdcDomainVersion(): string
    {
        return self::USDC_DOMAIN_VERSION;
    }

    /**
     * @param array<int, string> $types
     * @param array<int, mixed> $values
     */
    private static function encodeAbi(array $types, array $values): string
    {
        $parts = [];
        foreach ($types as $i => $type) {
            $value = $values[$i];
            $parts[] = match ($type) {
                'bytes32' => self::normalizeHex32($value),
                'uint256' => self::encodeUint256($value),
                'address' => self::encodeAddress($value),
                default => throw new \InvalidArgumentException("Unsupported ABI type: {$type}"),
            };
        }
        return '0x' . implode('', $parts);
    }

    private static function keccak256(string $value): string
    {
        if (str_starts_with($value, '0x')) {
            $value = hex2bin(substr($value, 2));
        }
        return '0x' . Keccak::hash($value, 256);
    }

    private static function encodeUint256(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    private static function encodeAddress(string $value): string
    {
        return str_pad(substr(self::normalizeAddress($value), 2), 64, '0', STR_PAD_LEFT);
    }

    private static function normalizeHex32(string $value): string
    {
        $hex = str_starts_with($value, '0x') ? substr($value, 2) : $value;
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private static function normalizeAddress(string $value): string
    {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $value)) {
            throw new \InvalidArgumentException("Invalid address: {$value}");
        }
        return '0x' . strtolower(substr($value, 2));
    }
}
