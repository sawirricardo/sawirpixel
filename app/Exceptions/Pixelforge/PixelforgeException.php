<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An error response from the Pixelforge API.
 */
final class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorType = null,
        public readonly ?string $param = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->fluent('error');
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $error->string('message', sprintf('Pixelforge request failed with HTTP %d.', $response->status()))->value(),
            status: $response->status(),
            errorCode: $error->filled('code') ? $error->string('code')->value() : null,
            errorType: $error->filled('type') ? $error->string('type')->value() : null,
            param: $error->filled('param') ? $error->string('param')->value() : null,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isOverloaded(): bool
    {
        return $this->status === 503;
    }

    /**
     * Rate limits and server errors are worth retrying; client errors are not.
     */
    public function isTransient(): bool
    {
        return $this->isRateLimited() || $this->status >= 500;
    }
}
