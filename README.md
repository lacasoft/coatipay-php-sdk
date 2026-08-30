# lacasoft/coatipay-sdk — PHP SDK

The CoatiPay PHP SDK — **Stripe-compatible payments for the open web**.
Accept **USDC on Base** with no gatekeepers: gasless settlement (ERC-3009), webhooks, and
x402 micropayments. ~1% protocol fee (0.7% nodeit / 0.3% treasury), settled trustlessly on-chain.

- ⛽ **Gasless for payers** — they sign an ERC-3009 authorization; the nodeit pays the gas.
- 🧩 **Stripe-like DX** — `paymentIntents->create(...)`, `webhooks->verify(...)`.
- 🌐 **Open network** — no lock-in: any nodeit can settle your payments, and anyone can run one.

## Install

```bash
composer require lacasoft/coatipay-sdk
```

Requires PHP ≥ 8.1. Depends on `guzzlehttp/guzzle`.

## Quick start

```php
<?php

require 'vendor/autoload.php';

use CoatiPay\CoatiPay;

// Use a SECRET key, server-side only — never ship it to a client.
$relay = new CoatiPay('sk_live_...');

$intent = $relay->paymentIntents->create(
    amount:   10_000_000,           // 10.00 USDC (6 decimals → 1 USDC = 1_000_000)
    currency: 'usdc',
    chain:    'base',
    metadata: ['order_id' => '123'],
);

echo $intent['id'], ' ', $intent['status'];  // "pi_…", "created"
```

Other payment-intent methods: `retrieve($id)`, `list($limit = 10, $startingAfter = null)`, `cancel($id)`.

## Gasless settlement with ERC-3009

Payers authorize USDC transfers off-chain with an EIP-712 signature. The nodeit
pays the gas to settle on-chain.

```php
use CoatiPay\Eip712;

$auth = Eip712::signAuthorization(
    payer:    '0xPayerAddress...',
    amount:   1_000_000,                       // 1.00 USDC
    settlementHub: '0xSettlementHubAddress...',
    chain:    'base',
    intentId: '0xIntentId...',                  // on-chain intent id (bytes32) — becomes the nonce
    privateKey: '0x...',                        // payer private key — server-side demo only
);

$relay->paymentIntents->submitAuthorization('pi_...', $auth);
```

`intentId` is required: the authorization's ERC-3009 nonce **is** that intent id.
The SettlementHub enforces `nonce == intentId`, so a signature can only ever pay
the intent it was signed for — the nodeit that submits the transaction cannot
redirect it to a different intent.

For batch settlement, pass a list of `['intent_id' => ..., 'authorization' => $auth]`
items to `$relay->paymentIntents->submitAuthorizationBatch($items)` (max 50 per batch).

## x402 micropayments

Protect a route with a PSR-15 middleware that returns `402 Payment Required` when
the `X-PAYMENT` header is missing or invalid.

```php
use CoatiPay\X402\X402Middleware;

$relay = new CoatiPay('sk_live_...', merchantWallet: '0xMerchantWallet...');

$app->add(new X402Middleware($relay, [
    'price'       => 1_000,     // 0.001 USDC
    'currency'    => 'usdc',
    'chain'       => 'base',
    'description' => 'Premium API access',
]));
```

## Webhooks

```php
$event = $relay->webhooks->verify(
    $payload,                                   // raw request body (string)
    $_SERVER['HTTP_X_SIGNATURE'],       // X-Signature header
    'whsec_...',
);

if ($event['type'] === 'payment_intent.settled') {
    fulfillOrder($event['data']['metadata']['order_id']);
}
```

`verify()` checks the HMAC-SHA256 signature and rejects payloads whose timestamp is older
than 5 minutes (replay protection). It throws `\InvalidArgumentException` on any mismatch.

## Configuration

```php
$relay = new CoatiPay(
    apiKey:         'sk_live_...',                  // required — secret key, server-side only
    baseUrl:        'https://api.coatipay.com',   // optional — your CoatiPay API host
    timeout:        30.0,                           // optional — seconds
    merchantWallet: '0x...',                         // optional — receives x402 payments
);
```

## Economics

The protocol fee is ~1% (0.7% nodeit / 0.3% treasury), settled on-chain. The API enforces a
**minimum payment floor (~$0.30)** — intents below it are rejected, because below that point the
protocol fee can't cover settlement gas even when batched. Sub-cent x402 micropayments are on the
roadmap via off-chain **netting** (aggregating many tiny payments into one on-chain settlement).

## Links

- Repo, docs & protocol spec: https://github.com/lacasoft/coatipay-protocol
- Source: [`coatipay-php-sdk`](https://github.com/lacasoft/coatipay-php-sdk)
- License: Apache-2.0
