# Changelog

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
