<?php

declare(strict_types=1);

namespace CoatiPay\Tests;

use CoatiPay\Eip712;
use CoatiPay\Errors\CoatiPaySDKError;
use CoatiPay\Errors\ErrorHandler;
use CoatiPay\Errors\WebhookSignatureError;
use CoatiPay\Webhooks;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vectores compartidos entre los SDK de CoatiPay (JS, Python, PHP).
 *
 * El mismo juego de casos que pasan los otros SDK, publicado en
 * `@lacasoft/coatipay-protocol` (`vectors/`). `tests/vectors/` es una copia: el
 * CI comprueba que es la de la última versión publicada. Si este SDK se
 * desvía, falla aquí y no en producción.
 */
class VectoresTest extends TestCase
{
    private static function vector(string $nombre): array
    {
        $dir = getenv('COATIPAY_VECTORES') ?: __DIR__ . '/vectors';
        return json_decode((string) file_get_contents("{$dir}/{$nombre}"), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── nonce ──────────────────────────────────────────────────────

    public static function nonces(): iterable
    {
        foreach (self::vector('nonce.json')['casos'] as $c) {
            yield json_encode($c['intent_id']) => [$c['intent_id'], $c['nonce']];
        }
    }

    #[DataProvider('nonces')]
    public function testNonce(string $intentId, string $nonce): void
    {
        $this->assertSame($nonce, Eip712::intentIdToBytes32($intentId));
    }

    public static function rechazados(): iterable
    {
        foreach (self::vector('nonce.json')['rechazados'] as $r) {
            yield $r['motivo'] => [$r['intent_id']];
        }
    }

    #[DataProvider('rechazados')]
    public function testNonceRechazado(string $intentId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Eip712::intentIdToBytes32($intentId);
    }

    // ── autorización ERC-3009 ──────────────────────────────────────

    public static function autorizaciones(): iterable
    {
        foreach (self::vector('autorizacion.json')['casos'] as $c) {
            yield "{$c['entrada']['chain']}-{$c['entrada']['intent_id']}" => [$c];
        }
    }

    /// Las direcciones, sin distinguir mayúsculas: el checksum EIP-55 es
    /// presentación (se firma sobre los bytes).
    private static function sinMayusculas(array $d, array $campos): array
    {
        foreach ($campos as $k) {
            $d[$k] = strtolower((string) $d[$k]);
        }
        return $d;
    }

    private static function argumentos(array $c): array
    {
        $e = $c['entrada'];
        return [
            $c['esperado']['api_body']['payer'],
            (int) $e['amount'],
            $e['settlement_hub'],
            $e['chain'],
            $e['intent_id'],
        ];
    }

    private static function opciones(array $c): array
    {
        return [
            'validAfter' => (int) $c['entrada']['valid_after'],
            'validBefore' => (int) $c['entrada']['valid_before'],
        ];
    }

    #[DataProvider('autorizaciones')]
    public function testDominioMensajeYDigest(array $c): void
    {
        $e = $c['esperado'];
        $tipado = Eip712::buildAuthorizationTypedData(...[...self::argumentos($c), self::opciones($c)]);
        $this->assertEquals($e['domain'], $tipado['domain']);
        $mensaje = array_map('strval', $tipado['message']);
        $this->assertSame(
            self::sinMayusculas($e['message'], ['from', 'to']),
            self::sinMayusculas($mensaje, ['from', 'to']),
        );
        $this->assertSame($e['digest'], Eip712::hashTypedData($tipado));
    }

    #[DataProvider('autorizaciones')]
    public function testFirmaYCuerpoDeLaApi(array $c): void
    {
        $e = $c['esperado'];
        $firmada = Eip712::signAuthorization(
            ...[...self::argumentos($c), $c['entrada']['payer_private_key'], self::opciones($c)],
        );
        $this->assertSame($e['signature'], $firmada->signature);
        $this->assertSame(
            self::sinMayusculas($e['api_body'], ['payer']),
            self::sinMayusculas(Eip712::serializeAuthorization($firmada), ['payer']),
        );
    }

    // ── webhooks ───────────────────────────────────────────────────

    public static function webhooks(): iterable
    {
        foreach (self::vector('webhooks.json')['casos'] as $c) {
            yield $c['nombre'] => [$c];
        }
    }

    #[DataProvider('webhooks')]
    public function testWebhook(array $c): void
    {
        $w = self::vector('webhooks.json');
        $opciones = ['now' => $w['ahora']];
        if (isset($c['tolerancia'])) {
            $opciones['tolerance'] = $c['tolerancia'];
        }
        $webhooks = new Webhooks(new Client()); // verificar no llama a la API
        $verificar = fn () => $webhooks->verify($c['cuerpo'] ?? $w['cuerpo'], $c['cabecera'], $w['secreto'], $opciones);

        if ($c['esperado']['valida']) {
            $evento = $verificar();
            if (!isset($c['cuerpo'])) {
                $this->assertSame($w['evento'], $evento);
            }
            return;
        }
        try {
            $verificar();
            $this->fail('La firma debía rechazarse: ' . $c['esperado']['motivo']);
        } catch (WebhookSignatureError $e) {
            $this->assertSame($c['esperado']['motivo'], $e->reason);
        }
    }

    // ── errores ────────────────────────────────────────────────────

    public static function errores(): iterable
    {
        foreach (self::vector('errores.json')['codigos'] as $code => $d) {
            yield $code => [$code, $d['clase']];
        }
    }

    #[DataProvider('errores')]
    public function testErrorDeCadaCodigo(string $code, string $clase): void
    {
        $e = ErrorHandler::classify(['code' => $code, 'message' => 'm']);
        $this->assertSame($clase, (new \ReflectionClass($e))->getShortName());
        $this->assertInstanceOf(CoatiPaySDKError::class, $e);
    }

    public function testErrorDesconocidoEsLaClaseBase(): void
    {
        $d = self::vector('errores.json')['desconocido'];
        $e = ErrorHandler::classify(['code' => $d['code'], 'message' => 'm']);
        $this->assertSame($d['clase'], (new \ReflectionClass($e))->getShortName());
    }
}
