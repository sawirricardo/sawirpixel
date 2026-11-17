<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A ledger entry collided with one that already exists (a generation held, released or charged twice,
 * or a replayed purchase). Deliberately not a UniqueConstraintViolationException, so callers that treat
 * those as harmless duplicates (like the webhook handler) don't swallow a money error.
 */
final class CreditLedgerConflictException extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self('A credit ledger entry already exists for this operation.', previous: $previous);
    }
}
