<?php

namespace CoatiPay\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use CoatiPay\CoatiPay;
use CoatiPay\Errors\CoatiPaySDKError;

class PaymentIntentsTest extends TestCase
{
    private function createClientWithMockResponse(Response $response): CoatiPay
    {
        $mock = new MockHandler([$response]);
        $handlerStack = HandlerStack::create($mock);
        $http = new Client(['handler' => $handlerStack]);

        // We need to inject the mock client. Since CoatiPay constructs its own
        // Guzzle client, we use reflection to replace it after construction.
        $client = new CoatiPay('sk_live_test');
        $ref = new \ReflectionClass($client);
        $httpProp = $ref->getProperty('http');
        $httpProp->setAccessible(true);
        $httpProp->setValue($client, $http);

        $piRef = new \ReflectionClass($client->paymentIntents);
        $piHttpProp = $piRef->getProperty('http');
        $piHttpProp->setAccessible(true);
        $piHttpProp->setValue($client->paymentIntents, $http);

        return $client;
    }

    public function testCreateSendsCorrectPathAndBody(): void
    {
        $client = $this->createClientWithMockResponse(new Response(201, [], json_encode([
            'id' => 'pi_test',
            'status' => 'created',
        ])));

        $result = $client->paymentIntents->create(10_000_000, 'usdc', 'base', ['order_id' => '123']);

        $this->assertEquals('pi_test', $result['id']);
    }

    public function testRetrieveSendsGet(): void
    {
        $client = $this->createClientWithMockResponse(new Response(200, [], json_encode([
            'id' => 'pi_123',
            'status' => 'pending_payment',
        ])));

        $result = $client->paymentIntents->retrieve('pi_123');

        $this->assertEquals('pi_123', $result['id']);
    }

    public function testApiErrorRaisesCoatiPaySDKError(): void
    {
        $client = $this->createClientWithMockResponse(new Response(401, [], json_encode([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => 'Invalid or revoked API key.',
                'param' => null,
                'doc_url' => 'https://docs.coatipay.com/errors/invalid_api_key',
            ],
        ])));

        $this->expectException(CoatiPaySDKError::class);
        $this->expectExceptionMessage('Invalid or revoked API key.');

        try {
            $client->paymentIntents->list();
        } catch (CoatiPaySDKError $e) {
            $this->assertEquals('invalid_api_key', $e->errorCode);
            throw $e;
        }
    }

    public function testApiErrorUnknownFormat(): void
    {
        $client = $this->createClientWithMockResponse(new Response(500, [], json_encode(['error' => []])));

        $this->expectException(CoatiPaySDKError::class);
        $this->expectExceptionMessage('Unknown error');

        $client->paymentIntents->retrieve('pi_fail');
    }

    private function clientWithMock(Response $response, MockHandler &$mock = null): CoatiPay
    {
        $mock = new MockHandler([$response]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new CoatiPay('sk_live_test');
        $piRef = new \ReflectionClass($client->paymentIntents);
        $prop = $piRef->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($client->paymentIntents, $http);
        return $client;
    }

    public function testSubmitAuthorizationUsesV1Path(): void
    {
        $client = $this->clientWithMock(new Response(200, [], json_encode(['status' => 'queued'])), $mock);
        $auth = new \CoatiPay\SignedAuthorization(
            '0x' . str_repeat('aa', 20),
            0,
            2_000_000_000,
            '0x' . str_repeat('00', 32),
            '0x' . str_repeat('11', 65),
        );

        $client->paymentIntents->submitAuthorization('pi_abc', $auth);

        $req = $mock->getLastRequest();
        $this->assertEquals('POST', $req->getMethod());
        $this->assertEquals('/v1/payment_intents/pi_abc/authorize', $req->getUri()->getPath());
    }

    public function testSubmitAuthorizationBatchUsesV1Path(): void
    {
        $client = $this->clientWithMock(new Response(200, [], json_encode(['queued' => 1])), $mock);
        $auth = new \CoatiPay\SignedAuthorization(
            '0x' . str_repeat('aa', 20),
            0,
            2_000_000_000,
            '0x' . str_repeat('00', 32),
            '0x' . str_repeat('11', 65),
        );

        $client->paymentIntents->submitAuthorizationBatch([
            ['intent_id' => 'pi_1', 'authorization' => $auth],
        ]);

        $req = $mock->getLastRequest();
        $this->assertEquals('/v1/payment_intents/batch/authorize', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertEquals('pi_1', $body['items'][0]['intent_id']);
    }
}
