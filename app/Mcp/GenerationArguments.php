<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Concerns\ValidatesGenerationInput;
use Laravel\Mcp\Request;

/**
 * A tool call's arguments with the same validation rules and typed accessors as the web and API
 * generation requests, so all three accept exactly the same input.
 */
final class GenerationArguments extends Request
{
    use ValidatesGenerationInput;

    public static function from(Request $request): self
    {
        return new self($request->all());
    }
}
