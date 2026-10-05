# lacasoft/coatipay-sdk — PHP SDK

The CoatiPay PHP SDK — **Stripe-compatible payments for the open web**.
Accept **USDC on Base** with no gatekeepers: gasless settlement (ERC-3009), webhooks, and
x402 micropayments. 1.5% protocol fee (1.05% nodeit / 0.45% treasury), settled trustlessly on-chain.

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
    // Safe to retry: the same key with the same parameters returns the same intent.
    idempotencyKey: 'order_123',
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
    intentId: $intent['id'],                    // the "pi_…" the API returned — becomes the nonce
    privateKey: '0x...',                        // payer private key — server-side demo only
);

$relay->paymentIntents->submitAuthorization($intent['id'], $auth);
```

`intentId` is required: the authorization's ERC-3009 nonce **is** that intent.
The SettlementHub enforces `nonce == keccak256(utf8(intentId))`, so a signature
can only ever pay the intent it was signed for — the nodeit that submits the
transaction cannot redirect it to a different intent.

Pass the plain `pi_…` id: the SDK derives the on-chain `bytes32` for you, so
there is no hash to get wrong. If you build the typed data yourself, derive it
with the same helper the SDK uses:

```php
$nonce = Eip712::intentIdToBytes32('pi_abc123'); // 0x… (32 bytes)
```

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
use CoatiPay\Errors\WebhookSignatureError;

try {
    $event = $relay->webhooks->verify(
        file_get_contents('php://input'),       // the RAW request body
        $_SERVER['HTTP_X_SIGNATURE'] ?? '',     // X-Signature header
        'whsec_...',
    );
} catch (WebhookSignatureError $e) {
    http_response_code(400);
    exit($e->reason);
}

// At least once and in no particular order: deduplicate by $event['id'].
if ($event['type'] === 'payment_intent.settled') {
    fulfillOrder($event['data']['metadata']['order_id']);
}
```

- `verify()` checks the HMAC-SHA256 signature in constant time and rejects a timestamp more
  than 5 minutes away from now (replay protection): `['tolerance' => …]` changes it, in
  seconds.
- On failure it throws `WebhookSignatureError` (an `\InvalidArgumentException`) with a
  `reason`: `malformed_header`, `timestamp_out_of_tolerance` or `no_matching_signature`.
- Events: `payment_intent.created`, `payment_intent.settled`, `payment_intent.expired`,
  `payment_intent.cancelled`.
- **Rotating the secret.** `rotateSecret()` returns a new secret — once: store it. The
  previous one keeps signing next to it for 24 hours (second argument, in seconds, up to
  7 days), and `verify()` accepts either, so you change the secret on your server without
  dropping a delivery. If a secret leaked, `0` retires it at once.

  ```php
  $rotated = $relay->webhooks->rotateSecret('we_…');  // $rotated['secret']
  $relay->webhooks->rotateSecret('we_…', 0);          // leaked: stop now
  ```
- **Deliveries that exhausted their retries** stay in a dead-letter queue:

  ```php
  $dead = $relay->webhooks->listDeadLetters(20);
  $relay->webhooks->replayDeadLetter($dead['data'][0]['id']);  // same event, same id
  ```

## Errors

```php
use CoatiPay\Errors\CoatiPaySDKError;
use CoatiPay\Errors\NetworkError;

try {
    $relay->paymentIntents->create($amount, 'usdc', 'base', idempotencyKey: $orderId);
} catch (NetworkError $e) {
    // No CoatiPay answer ($e->status: the HTTP status, or null). Whether it took effect is
    // unknown: retrying with the same idempotencyKey returns the same intent.
} catch (CoatiPaySDKError $e) {
    echo $e->errorCode, ' ', $e->getMessage(), ' ', $e->param, ' ', $e->docUrl;
}
```

Everything a call throws is a `CoatiPaySDKError`, with `errorCode`, the message, `param` and
`docUrl` (the code's page at [coatipay.com/docs/errors](https://coatipay.com/docs/errors/)).
The class tells the kind: `AuthError`, `ValidationError`, `RoutingError`, `PaymentError`,
`RateLimitError`, or the base class for the rest and for a code this version does not know.
`NetworkError` is one too, so catch it first. The same classes and rules in the JS and
Python SDKs.

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

The protocol fee is 1.5% (1.05% nodeit / 0.45% treasury), settled on-chain. The API enforces a
**minimum payment floor (~$0.30)** — intents below it are rejected, because around that point the
protocol fee stops covering settlement gas reliably, even when batched. Sub-cent x402
micropayments are on the roadmap via off-chain **netting** (aggregating many tiny payments into
one on-chain settlement).

## Links

- Repo, docs & protocol spec: https://github.com/lacasoft/coatipay-protocol
- Source: [`coatipay-php-sdk`](https://github.com/lacasoft/coatipay-php-sdk)
- License: Apache-2.0
