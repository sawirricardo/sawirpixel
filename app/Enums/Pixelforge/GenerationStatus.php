<?php

declare(strict_types=1);

namespace App\Enums\Pixelforge;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Succeeded and failed are terminal; a generation never leaves them.
     */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
