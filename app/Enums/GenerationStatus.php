<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a generation in this app. Pending means it has not been accepted by Pixelforge yet.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
