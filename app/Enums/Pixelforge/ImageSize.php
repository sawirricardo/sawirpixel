<?php

declare(strict_types=1);

namespace App\Enums\Pixelforge;

use InvalidArgumentException;

enum ImageSize: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';

    /**
     * Resolve a Pixelforge size from a pixel size or a laravel/ai aspect ratio (1:1, 3:2, 2:3).
     *
     * @throws InvalidArgumentException when the size is not supported by Pixelforge
     */
    public static function fromSizeOrAspectRatio(?string $size): self
    {
        return match ($size) {
            null, '1:1' => self::Square,
            '3:2' => self::Landscape,
            '2:3' => self::Portrait,
            default => self::tryFrom($size) ?? throw new InvalidArgumentException(sprintf(
                'Pixelforge does not support the image size [%s]. Use 1024x1024, 1536x1024 or 1024x1536.',
                $size,
            )),
        };
    }
}
