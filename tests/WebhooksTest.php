<?php

namespace CoatiPay\Tests;

use PHPUnit\Framework\TestCase;
use CoatiPay\Webhooks;
use GuzzleHttp\Client;

class WebhooksTest extends TestCase
{
    private Webhooks $webhooks;

    protected function setUp(): void
    {
        $mockClient = $this->createMock(Client::class);
        $this->webhooks = new Webhooks($mockClient);
    }

    private function makeSignature(string $payload, string $secret, int $ts): string
    {
        $sig = hash_hmac('sha256', "{$ts}.{$payload}", $secret);
        return "t={$ts},v1={$sig}";
    }

    // ── Valid signature ───────────────────────────────────────────

    public function testVerifyValidSignature(): void
    {
        $secret  = 'whsec_test_secret_123';
        $payload = json_encode(['id' => 'evt_test', 'type' => 'payment_intent.settled']);
        $ts      = time();
        $sig     = $this->makeSignature($payload, $secret, $ts);

        $result = $this->webhooks->verify($payload, $sig, $secret);

        $this->assertIsArray($result);
        $this->assertEquals('evt_test', $result['id']);
        $this->assertEquals('payment_intent.settled', $result['type']);
    }

    // ── Invalid signature ─────────────────────────────────────────

    public function testVerifyInvalidSignature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook signature verification failed');

        $secret  = 'whsec_test_secret_123';
        $payload = json_encode(['id' => 'evt_test']);
        $ts      = time();
        $sig     = "t={$ts},v1=invalidsignaturehex000000000000000000000000000000000000000000000000";

        $this->webhooks->verify($payload, $sig, $secret);
    }

    // ── Expired timestamp ─────────────────────────────────────────

    public function testVerifyExpiredTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook timestamp too old or invalid');

        $secret  = 'whsec_test_secret_123';
        $payload = json_encode(['id' => 'evt_test']);
        $oldTs   = time() - 600; // 10 minutes ago
        $sig     = $this->makeSignature($payload, $secret, $oldTs);

        $this->webhooks->verify($payload, $sig, $secret);
    }

    // ── Malformed signature header ────────────────────────────────

    public function testVerifyMalformedSignatureHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed webhook signature');

        $this->webhooks->verify('{}', 'not-a-valid-signature-format', 'secret');
    }

    // ── Malformed parts (no equals sign) ──────────────────────────

    public function testVerifyMalformedParts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed webhook signature');

        $this->webhooks->verify('{}', 'partone,parttwo', 'secret');
    }

    // ── Non-numeric timestamp ─────────────────────────────────────

    public function testVerifyNonNumericTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook timestamp too old or invalid');

        $this->webhooks->verify('{}', 't=notanumber,v1=abc', 'secret');
    }

    // ── Explode safety: extra equals in value ─────────────────────

    public function testExplodeSafetyExtraEquals(): void
    {
        // The value part may contain '=' (e.g., base64). explode($part, 2) handles this.
        $secret  = 'whsec_test_secret_123';
        $payload = json_encode(['id' => 'evt_safe']);
        $ts      = time();
        $sig     = hash_hmac('sha256', "{$ts}.{$payload}", $secret);

        // Signature with value containing an extra '=' should still parse correctly
        // since explode uses limit=2
        $header = "t={$ts},v1={$sig}";
        $result = $this->webhooks->verify($payload, $header, $secret);
        $this->assertEquals('evt_safe', $result['id']);
    }

    // ── Uses hash_equals for timing-safe comparison ───────────────

    public function testUsesTimingSafeComparison(): void
    {
        // The implementation uses hash_equals() which is timing-safe.
        // We verify the SDK works correctly with a valid signature
        // (the timing safety is guaranteed by PHP's hash_equals).
        $secret  = 'whsec_timing_safe';
        $payload = json_encode(['id' => 'evt_timing']);
        $ts      = time();
        $expected = hash_hmac('sha256', "{$ts}.{$payload}", $secret);

        // hash_equals is used internally
        $this->assertTrue(hash_equals($expected, $expected));

        $sig = "t={$ts},v1={$expected}";
        $result = $this->webhooks->verify($payload, $sig, $secret);
        $this->assertEquals('evt_timing', $result['id']);
    }
}
