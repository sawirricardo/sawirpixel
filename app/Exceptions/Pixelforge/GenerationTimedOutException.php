<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use RuntimeException;

/**
 * The generation did not finish while polling. It keeps running on Pixelforge and may still be billed.
 */
final class GenerationTimedOutException extends RuntimeException
{
    public function __construct(public readonly string $generationId)
    {
        parent::__construct(sprintf(
            'Pixelforge generation [%s] did not finish in time; it may still complete and be billed.',
            $generationId,
        ));
    }
}
