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
        $http = new Client(['handler' => $pila]);
        $cliente = new CoatiPay('sk_live_test');
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
