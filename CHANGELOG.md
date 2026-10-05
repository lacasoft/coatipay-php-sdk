# Changelog

## 0.1.5 — 2026-10-06

### Added

- **`webhooks->rotateSecret($id, $keepPreviousFor)`**: rotates an endpoint's
  signing secret (`POST /v1/webhooks/:id/rotate_secret`) and returns the new
  one, once. The previous secret keeps signing next to it for
  `$keepPreviousFor` seconds — 24 h by default, up to 7 days — and `verify()`
  accepts either, so the secret can be changed without dropping a delivery.
  `0` retires the previous secret at once, for one that leaked.

### Fixed

- **`paymentIntents->cancel` and `webhooks->replayDeadLetter` never reached the
  API.** The client declared `Content-Type: application/json` on every request,
  and the API rejects a POST that declares JSON and arrives empty (400
  `invalid_request`, "Body cannot be empty when content-type is set to
  'application/json'"). The header is now only sent with a body. Found by
  running the SDK against a real API; the tests replaced the SDK's HTTP client
  with a bare one, without its default headers.

### Docs

- README: rotating a webhook secret. The workaround of registering a second
  endpoint is no longer needed.

## 0.1.4 — 2026-09-29

### Changed

- **One rule for the API's response, shared by every CoatiPay SDK** (vectors in
  `@lacasoft/coatipay-protocol/vectors/errores.json`, `respuestas`):
  - No response (network, DNS, timeout) throws **`NetworkError`** (`status`
    null). It used to throw a `CoatiPaySDKError` with code `network_error`,
    which `NetworkError` still is.
  - A body that is not JSON — a proxy's HTML 502, an empty 503 — throws
    `NetworkError` with its `status`. It used to throw a `CoatiPaySDKError`
    with code `unknown_error`. **A 2xx that is not JSON used to return `[]`
    silently**; it throws `NetworkError` now.
  - An error response that is not a CoatiPay error (no `error.code`, such as
    Fastify's default error) throws `NetworkError`, not `unknown_error`.
  - A non-string `message`, `param` or `doc_url` in an API error no longer
    causes a `TypeError`.
- `CoatiPaySDKError` takes an optional `$previous`: the exception that caused
  it (for `NetworkError`, the Guzzle one).

### Added

- **`paymentIntents->create(..., idempotencyKey: ...)`**, sent as the
  `Idempotency-Key` header: the same key with the same parameters returns the
  same intent, so a create can be retried after a `NetworkError`. Only the JS
  SDK had it.
- `CoatiPay\Errors\NetworkError`, with `status`.

### Docs

- README: error handling, `idempotencyKey`, webhook failure reasons, the dead-
  letter queue, and how to change a webhook secret today (the API does not
  rotate secrets yet).

## 0.1.3 — 2026-09-28

### Fixed

- **`intentIdToBytes32` rejects a whitespace-only id**, as it already did an
  empty one. It used to hash it into a nonce that looks valid and belongs to no
  intent. Found by the shared vectors.
- **`webhooks->verify` accepts a secret rotation**: the request is valid if
  **any** `v1` matches. It used to check only the last one.
- **`webhooks->verify` no longer takes `1e3` or `12.5` as a timestamp**
  (`is_numeric`): `t` must be all digits.
- **`docUrl` fallback.** When the API sends none, it points to the code's real
  page (`https://coatipay.com/docs/errors/<code>`), or to the error index for a
  code outside the catalog; it used to be `https://docs.coatipay.com`, which
  does not exist.

### Changed

- **`webhooks->verify` follows the rules shared by every CoatiPay SDK**
  (vectors in `@lacasoft/coatipay-protocol`): `t` once, spaces around parts
  ignored, `['tolerance' => …, 'now' => …]` options. It throws
  `WebhookSignatureError` — still an `InvalidArgumentException`, with the same
  messages — carrying a `reason`: `malformed_header`,
  `timestamp_out_of_tolerance` or `no_matching_signature`.
- **API errors are thrown with their class**: `AuthError`, `ValidationError`,
  `RoutingError`, `PaymentError` or `RateLimitError` by the code's catalog
  category, as in the JS and Python SDKs. All of them extend
  `CoatiPaySDKError`, so existing `catch (CoatiPaySDKError $e)` still works.

### Added

- `webhooks->listDeadLetters(?int $limit)` and
  `webhooks->replayDeadLetter(string $id)`: deliveries that exhausted their
  retries, and sending one again.
- **Tests against the shared vectors** (`tests/vectors`, a copy of the latest
  published protocol's, checked in CI): the authorization nonce, the full
  ERC-3009 authorization per network (domain, message, digest, signature and
  API body — this SDK hashes EIP-712 by hand, and it matches), the 21 webhook
  cases and every error class. `src/Errors/Catalogo.php` is generated from
  them (`php bin/generar-catalogo.php`).

## Withdrawn versions — 2026-09-27

**v0.1.0 cannot complete a payment**: it signs with a random nonce, and the API and the
SettlementHub reject any authorization whose nonce is not the intent id (see 0.1.2 below).
Its tag was deleted, so **Packagist no longer offers it** (0.1.1 was never tagged). Use
**v0.1.2 or later**.

## 0.1.2 — 2026-09-01

### ⚠️ Breaking: `intentId` is now required when signing

The SettlementHub now requires the ERC-3009 authorization nonce to equal the
intent id. **Signatures produced by 0.1.1 and earlier are rejected on-chain**,
so upgrading is not optional if you are signing payments.

```diff
- $nonce = Eip712::generateNonce();
- $typed = Eip712::buildAuthorizationTypedData($payer, $amount, $hub, $chain, $nonce);
+ $typed = Eip712::buildAuthorizationTypedData($payer, $amount, $hub, $chain, $intentId);
```

Pass the **textual** intent id (`pi_…`) exactly as the API returns it. The SDK
derives the on-chain nonce itself; you do not need to hash anything. `Eip712::intentIdToBytes32` is
exported if you want to verify the derivation.

The random-nonce generator has been **removed**. There is no migration path that
keeps it: a random nonce is precisely the defect this release fixes.

### Why

A signed authorization was not bound to any particular intent. Because USDC
enforces `msg.sender == to`, the signed `to` is always the hub and can never
name a merchant — so the payment destination was decided by calldata that the
routing node controls. A malicious node could redirect a payment and keep
**997 of every 1000 USDC**.

Reported externally and fixed in ADR-004. Full write-up:
https://github.com/lacasoft/coatipay-protocol/blob/master/audits/adr/004-auth-binding-y-retirada-de-disputas.md

### Also

- Protocol fee is now **1.5%** (ADR-005), split 70/30 as before: 1.05% to the
  routing node, 0.45% to the treasury.
