<?php

declare(strict_types=1);

namespace CoatiPay\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use CoatiPay\Errors\ErrorHandler;
use CoatiPay\Errors\CoatiPaySDKError;
use CoatiPay\Errors\NetworkError;

class ApiRequest
{
    /**
     * Send an HTTP request through Guzzle and decode the JSON response.
     *
     * The same rule in every CoatiPay SDK (shared vectors:
     * `@lacasoft/coatipay-protocol/vectors/errores.json`, `respuestas`):
     * - no response (network, DNS, timeout) → NetworkError, status null;
     * - a body that is not JSON, even with a 2xx (a proxy's HTML 502) →
     *   NetworkError with the status;
     * - a CoatiPay error (an object whose `error` is an object with a
     *   non-empty `code`) → the class of its category;
     * - any other error response (Fastify's default, a proxy's JSON) →
     *   NetworkError with the status.
     *
     * @param array<string, mixed> $options
     * @return array<mixed>
     * @throws CoatiPaySDKError
     */
    public static function send(Client $http, string $method, string $path, array $options = []): array
    {
        try {
            // Every response goes through the rule below, whatever its status.
            $response = $http->request($method, $path, ['http_errors' => false] + $options);
        } catch (GuzzleException $e) {
            // Without a response: connection, DNS, TLS, timeout. The cause is
            // getPrevious().
            throw new NetworkError("Network error: {$path}", null, $e);
        }

        $status = $response->getStatusCode();
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new NetworkError("Response is not JSON (HTTP {$status}): {$path}", $status, $e);
        }

        if ($status >= 200 && $status < 300) {
            // The API always answers with an object or a list. A bare JSON
            // scalar cannot be returned as an array: not a CoatiPay answer.
            if (!is_array($data)) {
                throw new NetworkError("Response is not a JSON object (HTTP {$status}): {$path}", $status);
            }
            return $data;
        }

        $error = is_array($data) && !array_is_list($data) ? ($data['error'] ?? null) : null;
        if (!is_array($error) || array_is_list($error) || !is_string($error['code'] ?? null) || $error['code'] === '') {
            throw new NetworkError("Response is not a CoatiPay error (HTTP {$status}): {$path}", $status);
        }
        throw ErrorHandler::classify($error);
    }
}
