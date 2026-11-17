<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final readonly class TokenRange
{
    public function __construct(
        public int $min,
        public int $max,
    ) {}

    public static function exactly(int $tokens): self
    {
        return new self($tokens, $tokens);
    }
}
