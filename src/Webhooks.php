<?php

declare(strict_types=1);

namespace CoatiPay;

use GuzzleHttp\Client;
use CoatiPay\Errors\WebhookSignatureError;
use CoatiPay\Http\ApiRequest;

/**
 * Webhook verification and management.
 *
 * @example
 * $event = $relay->webhooks->verify($payload, $signature, $secret);
 */
class Webhooks
{
    public function __construct(private Client $http) {}

    /** Default tolerance for the `t=` timestamp: 5 minutes. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify a webhook's `X-Signature` header and return the decoded event.
     * Call it with the RAW request body.
     *
     * The same rules in every CoatiPay SDK (shared vectors in
     * `@lacasoft/coatipay-protocol/vectors/webhooks.json`):
     *   - `t=<seconds>,v1=<hex>` parts, comma-separated (spaces around them
     *     are ignored). A part without `=` or without a key, a missing or
     *     repeated `t`, a `t` that is not all digits, or no `v1` →
     *     `malformed_header`.
     *   - `|now - t|` above the tolerance (300 s) → `timestamp_out_of_tolerance`.
     *   - Valid if ANY `v1` is the HMAC-SHA256 of `<t>.<body>` with your
     *     secret (a secret can be rotated without dropping deliveries);
     *     otherwise `no_matching_signature`.
     *
     * @param array{tolerance?: int, now?: int} $options `now` (seconds) is for tests.
     * @throws WebhookSignatureError (an InvalidArgumentException) with the `reason`.
     */
    public function verify(string $payload, string $signature, string $secret, array $options = []): array
    {
        $tolerance = $options['tolerance'] ?? self::DEFAULT_TOLERANCE_SECONDS;
        $now = $options['now'] ?? time();

        $ts = [];
        $firmas = [];
        foreach (explode(',', $signature) as $bruta) {
            $parte = trim($bruta);
            $igual = strpos($parte, '=');
            if ($igual === false || $igual === 0) {
                throw new WebhookSignatureError('malformed_header', 'Malformed webhook signature');
            }
            $clave = substr($parte, 0, $igual);
            $valor = substr($parte, $igual + 1);
            if ($clave === 't') {
                $ts[] = $valor;
            } elseif ($clave === 'v1') {
                $firmas[] = $valor;
            }
        }
        // Los mensajes de antes se conservan donde el caso es el mismo.
        if (count($ts) === 1 && preg_match('/^\d+$/', $ts[0]) !== 1) {
            throw new WebhookSignatureError('malformed_header', 'Webhook timestamp too old or invalid');
        }
        if (count($ts) !== 1 || $firmas === []) {
            throw new WebhookSignatureError('malformed_header', 'Malformed webhook signature');
        }
        if (abs($now - (int) $ts[0]) > $tolerance) {
            throw new WebhookSignatureError('timestamp_out_of_tolerance', 'Webhook timestamp too old or invalid');
        }

        $esperada = hash_hmac('sha256', "{$ts[0]}.{$payload}", $secret);
        foreach ($firmas as $firma) {
            if (hash_equals($esperada, $firma)) {
                return json_decode($payload, true);
            }
        }
        throw new WebhookSignatureError('no_matching_signature', 'Webhook signature verification failed');
    }

    /**
     * Register an endpoint. The returned `secret` signs its deliveries and is
     * only returned here: store it.
     *
     * @param list<'payment_intent.created'|'payment_intent.settled'|'payment_intent.expired'|'payment_intent.cancelled'> $events
     */
    public function register(string $url, array $events): array
    {
        return ApiRequest::send($this->http, 'POST', '/v1/webhooks', [
            'json' => compact('url', 'events'),
        ]);
    }

    /**
     * Rotate an endpoint's signing secret. The new `secret` is only returned
     * here: store it. Secret key.
     *
     * The previous secret keeps signing next to the new one for
     * `$keepPreviousFor` seconds — 24 h by default, up to 7 days. Meanwhile
     * every delivery carries two `v1` signatures and `verify()` accepts either,
     * so you can change the secret on your server without dropping a delivery.
     * With `0` the previous secret stops signing at once: for one that leaked.
     * Only two secrets ever coexist: rotating again within the window retires
     * the oldest.
     *
     * @return array{id: string, url: string, events: list<string>, secret: string, previous_secret_expires_at: int|null}
     */
    public function rotateSecret(string $id, ?int $keepPreviousFor = null): array
    {
        return ApiRequest::send(
            $this->http,
            'POST',
            '/v1/webhooks/' . rawurlencode($id) . '/rotate_secret',
            // Sin plazo no se manda cuerpo: lo pone la API.
            $keepPreviousFor !== null ? ['json' => ['keep_previous_for' => $keepPreviousFor]] : [],
        );
    }

    /** Deliveries that exhausted their retries, newest first. Secret key. */
    public function listDeadLetters(?int $limit = null): array
    {
        return ApiRequest::send(
            $this->http,
            'GET',
            '/v1/webhooks/dead_letters',
            $limit !== null ? ['query' => ['limit' => $limit]] : [],
        );
    }

    /**
     * Send a dead letter again: the same event, with the same id, to the same
     * endpoint, retries reset. Secret key.
     */
    public function replayDeadLetter(string $id): array
    {
        return ApiRequest::send($this->http, 'POST', '/v1/webhooks/dead_letters/' . rawurlencode($id) . '/replay');
    }
}
