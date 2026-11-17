<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a personal access token may do through the API, chosen when it's created (like GitHub's scopes).
 */
enum TokenAbility: string
{
    case GenerationsRead = 'generations:read';
    case GenerationsCreate = 'generations:create';
    case CreditsRead = 'credits:read';

    public function description(): string
    {
        return match ($this) {
            self::GenerationsRead => 'Estimate prices and read your generations',
            self::GenerationsCreate => "Generate images, spending the organization's credits",
            self::CreditsRead => "Read the organization's credit balance",
        };
    }
}
