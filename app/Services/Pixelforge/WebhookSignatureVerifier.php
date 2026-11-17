<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Exceptions\Pixelforge\InvalidWebhookSignatureException;

/**
 * Verifies the Pixelforge-Signature header (t=<unix timestamp>,v1=<hex HMAC-SHA256>) against the raw request body.
 */
final readonly class WebhookSignatureVerifier
{
    public function __construct(
        private ?string $secret,
        private int $toleranceSeconds = 300,
    ) {}

    /**
     * @throws InvalidWebhookSignatureException
     */
    public function verify(string $payload, ?string $signatureHeader): void
    {
        if ($this->secret === null || $this->secret === '') {
            throw InvalidWebhookSignatureException::missingSecret();
        }

        [$timestamp, $signatures] = $this->parseHeader($signatureHeader ?? '');

        if (abs(now()->getTimestamp() - $timestamp) > $this->toleranceSeconds) {
            throw InvalidWebhookSignatureException::staleTimestamp();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw InvalidWebhookSignatureException::mismatch();
    }

    /**
     * @return array{int, list<string>}
     *
     * @throws InvalidWebhookSignatureException
     */
    private function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', mb_trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw InvalidWebhookSignatureException::malformedHeader();
        }

        return [$timestamp, $signatures];
    }
}
