<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Services\Pricing\Money;
use Illuminate\Notifications\Notification;

/**
 * An organization's credits dropped below the low-balance threshold, sent to the members who can buy
 * more before generating stops.
 */
final class LowCredits extends Notification
{
    public const string KIND = 'low_credits';

    public function __construct(public Organization $organization) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, organization_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::KIND,
            'organization_id' => $this->organization->id,
            'title' => sprintf('%s is running low on credits', $this->organization->name),
            'body' => sprintf('%s left. Buy more to keep generating.', Money::format($this->organization->credit_balance)),
            'url' => route('credits.index', absolute: false),
        ];
    }
}
