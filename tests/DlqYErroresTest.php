<?php

declare(strict_types=1);

namespace CoatiPay\Tests;

use CoatiPay\CoatiPay;
use CoatiPay\Errors\AuthError;
use CoatiPay\Errors\CoatiPaySDKError;
use CoatiPay\Errors\NetworkError;
use CoatiPay\Errors\RateLimitError;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Los métodos del DLQ, la clase de los errores de la API y su doc_url. */
class DlqYErroresTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $historial = [];

    private function cliente(Response $respuesta): CoatiPay
    {
        $pila = HandlerStack::create(new MockHandler([$respuesta]));
        $this->historial = [];
        $pila->push(Middleware::history($this->historial));
        $cliente = new CoatiPay('sk_live_test');
        // El cliente HTTP del SDK tal como lo configura, con sus cabeceras por
        // defecto; solo se le cambia adónde manda. Con un cliente pelado las
        // pruebas no verían lo que de verdad llega a la API.
        $real = (new \ReflectionClass($cliente))->getProperty('http');
        $http = new Client(['handler' => $pila] + $real->getValue($cliente)->getConfig());
        foreach ([$cliente, $cliente->webhooks, $cliente->paymentIntents] as $objeto) {
            $prop = (new \ReflectionClass($objeto))->getProperty('http');
            $prop->setAccessible(true);
            $prop->setValue($objeto, $http);
        }
        return $cliente;
    }

    private const ENTREGA = [
        'id' => 'dlq_1', 'endpoint_id' => 'we_1', 'endpoint_url' => 'https://example.com/hook',
        'event_id' => 'evt_1', 'event_type' => 'payment_intent.settled', 'delivery_id' => 'whd_1',
        'attempts' => 6, 'last_error' => 'HTTP 500', 'last_attempted_at' => 1790000000,
        'created_at' => 1790000000, 'replayed_at' => null, 'payload' => ['id' => 'evt_1'],
    ];

    public function testListDeadLettersPideGetConElLimite(): void
    {
        $cliente = $this->cliente(new Response(200, [], json_encode(['data' => [self::ENTREGA], 'has_more' => false])));
        $r = $cliente->webhooks->listDeadLetters(5);
        $peticion = $this->historial[0]['request'];
        $this->assertSame('GET', $peticion->getMethod());
        $this->assertSame('/v1/webhooks/dead_letters', $peticion->getUri()->getPath());
        $this->assertSame('limit=5', $peticion->getUri()->getQuery());
        $this->assertSame('whd_1', $r['data'][0]['delivery_id']);
    }

    public function testReplayDeadLetterPidePost(): void
    {
        $cliente = $this->cliente(new Response(202, [], json_encode([...self::ENTREGA, 'replayed_at' => 1790000100])));
        $r = $cliente->webhooks->replayDeadLetter('dlq_1');
        $peticion = $this->historial[0]['request'];
        $this->assertSame('POST', $peticion->getMethod());
        $this->assertSame('/v1/webhooks/dead_letters/dlq_1/replay', $peticion->getUri()->getPath());
        $this->assertSame(1790000100, $r['replayed_at']);
    }

    private const ROTADO = [
        'id' => 'we_1', 'url' => 'https://example.com/hook', 'events' => ['payment_intent.settled'],
        'secret' => 'whsec_nuevo', 'previous_secret_expires_at' => 1790086400,
    ];

    public function testRotateSecretPidePostSinCuerpoElPlazoLoPoneLaApi(): void
    {
        $cliente = $this->cliente(new Response(200, [], json_encode(self::ROTADO)));
        $r = $cliente->webhooks->rotateSecret('we_1');
        $peticion = $this->historial[0]['request'];
        $this->assertSame('POST', $peticion->getMethod());
        $this->assertSame('/v1/webhooks/we_1/rotate_secret', $peticion->getUri()->getPath());
        $this->assertSame('', (string) $peticion->getBody());
        // Sin cuerpo no se declara JSON: la API rechazaría un cuerpo vacío.
        $this->assertFalse($peticion->hasHeader('Content-Type'));
        $this->assertSame(self::ROTADO, $r);
    }

    public function testRotateSecretMandaKeepPreviousForTambienCuandoEsCero(): void
    {
        foreach ([0, 3600] as $plazo) {
            $cliente = $this->cliente(new Response(200, [], json_encode([...self::ROTADO, 'previous_secret_expires_at' => null])));
            $r = $cliente->webhooks->rotateSecret('we_1', $plazo);
            $peticion = $this->historial[0]['request'];
            $this->assertSame(['keep_previous_for' => $plazo], json_decode((string) $peticion->getBody(), true));
            $this->assertSame('application/json', $peticion->getHeaderLine('Content-Type'));
            $this->assertNull($r['previous_secret_expires_at']);
        }
    }

    public function testRotateSecretEscapaElIdEnLaRuta(): void
    {
        $cliente = $this->cliente(new Response(200, [], json_encode(self::ROTADO)));
        $cliente->webhooks->rotateSecret('we_1/../otra');
        $this->assertSame('/v1/webhooks/we_1%2F..%2Fotra/rotate_secret', $this->historial[0]['request']->getUri()->getPath());
    }

    public function testDuranteLaVentanaVerifyAceptaElSecretoNuevoYElAnterior(): void
    {
        $payload = json_encode(['id' => 'evt_r', 'type' => 'payment_intent.settled', 'data' => []]);
        $t = time();
        $firma = fn (string $secreto): string => hash_hmac('sha256', "{$t}.{$payload}", $secreto);
        // Como la manda la API tras rotar: primero la del nuevo, después la del anterior.
        $cabecera = "t={$t},v1={$firma('whsec_nuevo')},v1={$firma('whsec_anterior')}";
        $webhooks = (new CoatiPay('sk_live_test'))->webhooks;

        $this->assertSame('evt_r', $webhooks->verify($payload, $cabecera, 'whsec_nuevo')['id']);
        $this->assertSame('evt_r', $webhooks->verify($payload, $cabecera, 'whsec_anterior')['id']);
        try {
            $webhooks->verify($payload, $cabecera, 'whsec_otro');
            $this->fail('Debía rechazar un secreto que no es ninguno de los dos');
        } catch (\CoatiPay\Errors\WebhookSignatureError $e) {
            $this->assertSame('no_matching_signature', $e->reason);
        }
    }

    public function testUnPostSinCuerpoNoDeclaraJson(): void
    {
        // La API rechaza con 400 un POST que declara JSON y llega vacío.
        $llamadas = [
            'cancel' => fn (CoatiPay $c) => $c->paymentIntents->cancel('pi_1'),
            'replayDeadLetter' => fn (CoatiPay $c) => $c->webhooks->replayDeadLetter('dlq_1'),
            'rotateSecret' => fn (CoatiPay $c) => $c->webhooks->rotateSecret('we_1'),
        ];
        foreach ($llamadas as $nombre => $llamada) {
            $llamada($this->cliente(new Response(200, [], '{}')));
            $peticion = $this->historial[0]['request'];
            $this->assertSame('POST', $peticion->getMethod(), $nombre);
            $this->assertSame('', (string) $peticion->getBody(), $nombre);
            $this->assertFalse($peticion->hasHeader('Content-Type'), $nombre);
            $this->assertSame('Bearer sk_live_test', $peticion->getHeaderLine('Authorization'), $nombre);
        }
    }

    public function testUnPostConCuerpoSiDeclaraJson(): void
    {
        $llamadas = [
            'register' => fn (CoatiPay $c) => $c->webhooks->register('https://example.com/h', ['payment_intent.settled']),
            'rotateSecret con plazo' => fn (CoatiPay $c) => $c->webhooks->rotateSecret('we_1', 0),
        ];
        foreach ($llamadas as $nombre => $llamada) {
            $llamada($this->cliente(new Response(200, [], '{}')));
            $peticion = $this->historial[0]['request'];
            $this->assertSame('application/json', $peticion->getHeaderLine('Content-Type'), $nombre);
            $this->assertNotSame('', (string) $peticion->getBody(), $nombre);
        }
    }

    public function testUnErrorDeLaApiLlegaConSuClase(): void
    {
        foreach ([[401, 'invalid_api_key', AuthError::class], [429, 'rate_limited', RateLimitError::class]] as [$estado, $code, $clase]) {
            $cliente = $this->cliente(new Response($estado, [], json_encode(['error' => [
                'code' => $code, 'message' => 'm', 'param' => null, 'doc_url' => "https://coatipay.com/docs/errors/{$code}",
            ]])));
            try {
                $cliente->paymentIntents->retrieve('pi_x');
                $this->fail("Debía lanzar {$clase}");
            } catch (CoatiPaySDKError $e) {
                $this->assertInstanceOf($clase, $e);
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function testSinDocUrlApuntaALaPaginaRealDelCodigo(): void
    {
        // Antes era https://docs.coatipay.com, que no existe.
        $cliente = $this->cliente(new Response(404, [], json_encode(['error' => ['code' => 'intent_not_found', 'message' => 'm']])));
        try {
            $cliente->paymentIntents->retrieve('pi_x');
            $this->fail('Debía lanzar');
        } catch (CoatiPaySDKError $e) {
            $this->assertSame('https://coatipay.com/docs/errors/intent_not_found', $e->docUrl);
        }
        // Un código del SDK sin página propia apunta al índice; NetworkError, a la suya.
        $this->assertSame('https://coatipay.com/docs/errors/', (new CoatiPaySDKError('api_key_required', 'x'))->docUrl);
        $this->assertSame('https://coatipay.com/docs/errors/network_error', (new NetworkError('x'))->docUrl);
    }

    public function testCreateConIdempotencyKeyLaMandaEnLaCabecera(): void
    {
        $cliente = $this->cliente(new Response(201, [], json_encode(['id' => 'pi_1', 'status' => 'created'])));
        $cliente->paymentIntents->create(1000000, 'usdc', 'base', ['order_id' => 'o_1'], 'order_123');
        $peticion = $this->historial[0]['request'];
        $this->assertSame('order_123', $peticion->getHeaderLine('Idempotency-Key'));
        $this->assertArrayNotHasKey('idempotency_key', json_decode((string) $peticion->getBody(), true));
    }

    public function testCreateSinIdempotencyKeyNoMandaCabecera(): void
    {
        $cliente = $this->cliente(new Response(201, [], json_encode(['id' => 'pi_1', 'status' => 'created'])));
        $cliente->paymentIntents->create(1000000, 'usdc', 'base');
        $this->assertFalse($this->historial[0]['request']->hasHeader('Idempotency-Key'));
    }
}
