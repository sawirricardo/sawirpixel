<?php

declare(strict_types=1);

namespace App\Services\Generations;

/**
 * What a customer is told when a generation fails. The provider's own wording (and name) stays out of
 * the product: we keep its error code for operators and show our own explanation.
 */
final class FailureMessage
{
    public static function for(?string $code): string
    {
        return match ($code) {
            'content_policy_violation' => "This prompt can't be generated because it goes against our content policy. You have not been charged.",
            'daily_limit_exceeded' => "We've reached today's image limit. Try again after midnight UTC. You have not been charged.",
            'submission_failed' => "We couldn't start this image in time. You have not been charged.",
            default => "We couldn't generate this image. You have not been charged.",
        };
    }
}
