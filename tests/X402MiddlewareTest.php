<?php

namespace CoatiPay\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use CoatiPay\CoatiPay;
use CoatiPay\X402\X402Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;

class X402MiddlewareTest extends TestCase
{
    private function createClientWithMockResponse(Response $response): CoatiPay
    {
        $mock = new MockHandler([$response]);
        $handlerStack = HandlerStack::create($mock);
        $http = new Client(['handler' => $handlerStack]);

        $client = new CoatiPay('sk_live_x402test', 'https://api.test.coatipay.com', 30.0, '0xMerchantWallet123');
        $ref = new \ReflectionClass($client);
        $httpProp = $ref->getProperty('http');
        $httpProp->setAccessible(true);
        $httpProp->setValue($client, $http);

        return $client;
    }

    private function createHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                return (new HttpFactory())->createResponse(200)
                    ->withHeader('Content-Type', 'application/json');
            }
        };
    }

    public function testReturns402WhenNoPaymentHeader(): void
    {
        $client = $this->createClientWithMockResponse(new Response(200, [], '{}'));
        $middleware = new X402Middleware($client, [
            'price' => 5_000,
            'currency' => 'usdc',
            'chain' => 'base',
            'description' => 'Premium API access',
        ]);

        $request = new ServerRequest('GET', 'https://api.example.com/protected');
        $response = $middleware->process($request, $this->createHandler());

        $this->assertEquals(402, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(1, $body['x402Version']);
        $this->assertCount(1, $body['accepts']);
        $option = $body['accepts'][0];
        $this->assertEquals('exact', $option['scheme']);
        $this->assertEquals('base', $option['network']);
        $this->assertEquals('5000', $option['maxAmountRequired']);
        $this->assertEquals('Premium API access', $option['description']);
        $this->assertEquals('0xMerchantWallet123', $option['payTo']);
        $this->assertEquals('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', $option['asset']);
        $this->assertEquals(300, $option['maxTimeoutSeconds']);
        $this->assertEquals('application/json', $option['mimeType']);
    }

    public function testUsesDefaultDescription(): void
    {
        $client = $this->createClientWithMockResponse(new Response(200, [], '{}'));
        $middleware = new X402Middleware($client, [
            'price' => 1_000,
            'currency' => 'usdc',
            'chain' => 'base',
        ]);

        $request = new ServerRequest('GET', 'https://api.example.com/resource');
        $response = $middleware->process($request, $this->createHandler());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals('API access', $body['accepts'][0]['description']);
    }

    public function testPassesThroughWhenPaymentHeaderValid(): void
    {
        $client = $this->createClientWithMockResponse(new Response(200, [], json_encode([
            'verified' => true,
            'tx_hash' => '0xValidTx',
        ])));
        $middleware = new X402Middleware($client, [
            'price' => 1_000,
            'currency' => 'usdc',
            'chain' => 'base',
        ]);

        $request = (new ServerRequest('GET', 'https://api.example.com/protected'))
            ->withHeader('x-payment', 'base64encodedpaymentdata');
        $handler = $this->createHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $handler->calls);
    }

    public function testReturns402WhenVerificationFails(): void
    {
        $client = $this->createClientWithMockResponse(new Response(402, [], json_encode([
            'error' => [
                'code' => 'insufficient_payment',
                'message' => 'Insufficient payment',
            ],
        ])));
        $middleware = new X402Middleware($client, [
            'price' => 1_000,
            'currency' => 'usdc',
            'chain' => 'base',
        ]);

        $request = (new ServerRequest('GET', 'https://api.example.com/protected'))
            ->withHeader('x-payment', 'invalidpayment');
        $handler = $this->createHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(402, $response->getStatusCode());
        $this->assertEquals(0, $handler->calls);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Payment verification failed', $body['error']);
    }

    public function testReturns402WhenNetworkError(): void
    {
        $client = $this->createClientWithMockResponse(new Response(500, [], json_encode(['error' => []])));
        $middleware = new X402Middleware($client, [
            'price' => 1_000,
            'currency' => 'usdc',
            'chain' => 'base',
        ]);

        $request = (new ServerRequest('GET', 'https://api.example.com/protected'))
            ->withHeader('x-payment', 'somepayment');
        $response = $middleware->process($request, $this->createHandler());

        $this->assertEquals(402, $response->getStatusCode());
    }

    public function testIncludesResourceUrlInChallenge(): void
    {
        $client = $this->createClientWithMockResponse(new Response(200, [], '{}'));
        $middleware = new X402Middleware($client, [
            'price' => 500,
            'currency' => 'usdc',
            'chain' => 'base',
        ]);

        $request = new ServerRequest('GET', 'https://api.example.com/v1/resource/123');
        $response = $middleware->process($request, $this->createHandler());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals('https://api.example.com/v1/resource/123', $body['accepts'][0]['resource']);
    }

    public function testPayToEmptyWhenMerchantWalletNotConfigured(): void
    {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $handlerStack = HandlerStack::create($mock);
        $http = new Client(['handler' => $handlerStack]);

        $client = new CoatiPay('sk_live_x402test');
        $ref = new \ReflectionClass($client);
        $httpProp = $ref->getProperty('http');
        $httpProp->setAccessible(true);
        $httpProp->setValue($client, $http);

        $middleware = new X402Middleware($client, [
            'price' => 1_000,
            'currency' => 'usdc',
            'chain' => 'base',
        ]);

        $request = new ServerRequest('GET', 'https://api.example.com/resource');
        $response = $middleware->process($request, $this->createHandler());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals('', $body['accepts'][0]['payTo']);
    }

    public function testAdvertisesBaseUsdcDomain(): void
    {
        // Base mainnet: asset = mainnet USDC, domain name = "USD Coin" (NOT "USDC")
        $base = new X402Middleware(
            $this->createClientWithMockResponse(new Response(200, [], '{}')),
            ['price' => 1_000, 'currency' => 'usdc', 'chain' => 'base'],
        );
        $body = json_decode(
            (string) $base->process(new ServerRequest('GET', 'https://x/y'), $this->createHandler())->getBody(),
            true,
        );
        $this->assertEquals('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', $body['accepts'][0]['asset']);
        $this->assertEquals(['name' => 'USD Coin', 'version' => '2'], $body['accepts'][0]['extra']);
    }

    public function testRejectsUnsupportedChain(): void
    {
        // x402 is Base-only at the facilitator → constructing with another chain throws.
        $this->expectException(\InvalidArgumentException::class);
        new X402Middleware(
            $this->createClientWithMockResponse(new Response(200, [], '{}')),
            ['price' => 1_000, 'currency' => 'usdc', 'chain' => 'base-sepolia'],
        );
    }
}
