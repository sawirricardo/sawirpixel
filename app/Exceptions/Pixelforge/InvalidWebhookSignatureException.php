<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use RuntimeException;

final class InvalidWebhookSignatureException extends RuntimeException
{
    public static function missingSecret(): self
    {
        return new self('The Pixelforge webhook signing secret is not configured.');
    }

    public static function malformedHeader(): self
    {
        return new self('The Pixelforge-Signature header is missing or malformed.');
    }

    public static function staleTimestamp(): self
    {
        return new self('The Pixelforge webhook timestamp is outside the tolerance window.');
    }

    public static function mismatch(): self
    {
        return new self('The Pixelforge webhook signature does not match the payload.');
    }
}
