<?php

namespace CoatiPay\Tests;

use PHPUnit\Framework\TestCase;
use CoatiPay\CoatiPay;
use CoatiPay\Errors\CoatiPaySDKError;

class CoatiPayTest extends TestCase
{
    // ── Client initialization ─────────────────────────────────────

    public function testClientInitialization(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertInstanceOf(CoatiPay::class, $client);
    }

    public function testClientHasPaymentIntents(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertInstanceOf(\CoatiPay\PaymentIntents::class, $client->paymentIntents);
    }

    public function testClientHasWebhooks(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertInstanceOf(\CoatiPay\Webhooks::class, $client->webhooks);
    }

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(CoatiPaySDKError::class);
        $this->expectExceptionMessage('apiKey is required');

        new CoatiPay('');
    }

    // ── PaymentIntents methods exist ──────────────────────────────

    public function testPaymentIntentsHasCreateMethod(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertTrue(method_exists($client->paymentIntents, 'create'));
    }

    public function testPaymentIntentsHasRetrieveMethod(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertTrue(method_exists($client->paymentIntents, 'retrieve'));
    }

    public function testPaymentIntentsHasCancelMethod(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertTrue(method_exists($client->paymentIntents, 'cancel'));
    }

    public function testPaymentIntentsHasListMethod(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertTrue(method_exists($client->paymentIntents, 'list'));
    }

    // ── Custom base URL ───────────────────────────────────────────

    public function testCustomBaseUrl(): void
    {
        // Should not throw
        $client = new CoatiPay('sk_live_test123', 'https://custom.api.dev');

        $this->assertInstanceOf(CoatiPay::class, $client);
    }

    // ── Webhooks methods exist ────────────────────────────────────

    public function testWebhooksHasVerifyMethod(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertTrue(method_exists($client->webhooks, 'verify'));
    }

    public function testWebhooksHasRegisterMethod(): void
    {
        $client = new CoatiPay('sk_live_test123');

        $this->assertTrue(method_exists($client->webhooks, 'register'));
    }
}
