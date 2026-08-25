<?php

declare(strict_types=1);

namespace CoatiPay\X402;

use GuzzleHttp\Psr7\HttpFactory;
use CoatiPay\Eip712;
use CoatiPay\CoatiPay;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that requires an x402 payment header.
 *
 * @example
 * $app->add(new X402Middleware($relay, [
 *     'price'       => 1000,
 *     'currency'    => 'usdc',
 *     'chain'       => 'base',
 *     'description' => 'Premium API access',
 * ]));
 */
class X402Middleware implements MiddlewareInterface
{
    private const DEFAULT_DESCRIPTION = 'API access';
    private const MAX_TIMEOUT_SECONDS = 300;
    private const X402_VERSION = 1;
    private const SCHEME = 'exact';
    private const MIME_TYPE = 'application/json';
    /** Chains the CoatiPay facilitator can verify x402 on (API is Base-only today). */
    private const SUPPORTED_CHAINS = ['base'];

    private CoatiPay $relay;
    /** @var array<string, mixed> */
    private array $options;
    private \Psr\Http\Message\ResponseFactoryInterface $responseFactory;

    /**
     * @param array<string, mixed> $options price, chain, description.
     *   `currency` is accepted for x402-options parity (JS/Python SDKs);
     *   CoatiPay settles in USDC, which is advertised as the asset.
     */
    public function __construct(
        CoatiPay $relay,
        array $options = [],
        ?\Psr\Http\Message\ResponseFactoryInterface $responseFactory = null,
    ) {
        $chain = (string) ($options['chain'] ?? 'base');
        if (!in_array($chain, self::SUPPORTED_CHAINS, true)) {
            throw new \InvalidArgumentException(
                'x402 is only supported on: ' . implode(', ', self::SUPPORTED_CHAINS)
                . " (got '{$chain}'). The CoatiPay facilitator verifies x402 payments on Base only."
            );
        }
        $this->relay = $relay;
        $this->options = $options;
        $this->responseFactory = $responseFactory ?? new HttpFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $paymentHeader = $request->getHeaderLine('x-payment');

        if ($paymentHeader === '') {
            return $this->jsonResponse(
                $this->buildPaymentRequired((string) $request->getUri()),
                402,
            );
        }

        $valid = $this->relay->x402Verify(
            $paymentHeader,
            (int) ($this->options['price'] ?? 0),
            (string) ($this->options['chain'] ?? 'base'),
        );

        if (!$valid) {
            return $this->jsonResponse(['error' => 'Payment verification failed'], 402);
        }

        return $handler->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentRequired(string $resource): array
    {
        $chain = (string) ($this->options['chain'] ?? 'base');

        return [
            'x402Version' => self::X402_VERSION,
            'accepts' => [
                [
                    'scheme' => self::SCHEME,
                    'network' => $chain,
                    'maxAmountRequired' => (string) ($this->options['price'] ?? 0),
                    'resource' => $resource,
                    'description' => $this->options['description'] ?? self::DEFAULT_DESCRIPTION,
                    'mimeType' => self::MIME_TYPE,
                    'payTo' => $this->relay->merchantWallet ?? '',
                    'maxTimeoutSeconds' => self::MAX_TIMEOUT_SECONDS,
                    // Derived from the chain (not hardcoded): USDC's address and
                    // EIP-712 domain name differ per chain (base → "USD Coin",
                    // base-sepolia → "USDC"). A wrong name makes the payer sign
                    // the wrong domain → USDC rejects the settlement.
                    'asset' => Eip712::usdcAddress($chain),
                    'extra' => [
                        'name' => Eip712::usdcDomainName($chain),
                        'version' => Eip712::usdcDomainVersion(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(array $body, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        // Rewind so PSR-15 emitters that stream from the current position read
        // the full body instead of an empty tail.
        $response->getBody()->rewind();
        return $response;
    }
}
