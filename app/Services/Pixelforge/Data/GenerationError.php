<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

final readonly class GenerationError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
