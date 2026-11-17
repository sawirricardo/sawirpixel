<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class NotificationController
{
    /**
     * Open a notification: mark it read and go where it points.
     */
    public function show(string $notification, #[CurrentUser] User $user): RedirectResponse
    {
        $notification = $user->notifications()->findOrFail($notification);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        // Only our own paths: a notification must never send anyone to another site.
        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : to_route('home');
    }
}
