<?php

declare(strict_types=1);

namespace App\Exceptions;

use BackedEnum;
use LogicException;

/**
 * Something tried to move a record between states its state machine doesn't allow, e.g. a finished
 * generation back to processing. Always a bug: the caller should have checked canTransitionTo() first.
 */
final class InvalidStatusTransitionException extends LogicException
{
    public static function between(string $subject, BackedEnum $from, BackedEnum $to): self
    {
        return new self(sprintf('%s cannot move from %s to %s.', $subject, $from->value, $to->value));
    }
}
