<?php

declare(strict_types=1);

namespace CoatiPay\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\GuzzleException;
use CoatiPay\Errors\ErrorHandler;
use CoatiPay\Errors\CoatiPaySDKError;

class ApiRequest
{
    /**
     * Send an HTTP request through Guzzle and decode the JSON response.
     * API errors are normalized into CoatiPaySDKError.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws CoatiPaySDKError
     */
    public static function send(Client $http, string $method, string $path, array $options = []): array
    {
        try {
            $response = $http->request($method, $path, $options);
            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response !== null) {
                $body = json_decode((string) $response->getBody(), true) ?? [];
                throw ErrorHandler::classify($body['error'] ?? []);
            }
            throw new CoatiPaySDKError('network_error', $e->getMessage());
        } catch (GuzzleException $e) {
            throw new CoatiPaySDKError('network_error', $e->getMessage());
        }
    }
}
