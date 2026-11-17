<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use App\Services\Pixelforge\Data\Generation;
use RuntimeException;

/**
 * Pixelforge accepted the generation but it finished as failed. Failed generations are not billed.
 */
final class GenerationFailedException extends RuntimeException
{
    public function __construct(public readonly Generation $generation)
    {
        parent::__construct(sprintf(
            'Pixelforge generation [%s] failed: %s',
            $generation->id,
            $generation->error->message ?? 'unknown error',
        ));
    }
}
