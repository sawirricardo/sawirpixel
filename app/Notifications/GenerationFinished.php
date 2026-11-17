<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An image finished, successfully or not. Images take a while, so this tells people who moved on to
 * another page (or another tab) that the result is in.
 */
final class GenerationFinished extends Notification
{
    public const string KIND = 'generation_finished';

    public function __construct(public Generation $generation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, generation_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $succeeded = $this->generation->status === GenerationStatus::Succeeded;

        return [
            'kind' => self::KIND,
            'generation_id' => $this->generation->id,
            'title' => $succeeded ? 'Your image is ready' : "Your image couldn't be generated",
            'body' => Str::limit($this->generation->prompt, 80),
            'url' => route('generations.show', $this->generation, absolute: false),
        ];
    }
}
