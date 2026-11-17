<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Response;

/**
 * Serves a generation's image from our own address, so the image provider never appears in the
 * product. Nothing is stored: the image is fetched from the provider's signed link on request and the
 * browser may cache it until that link expires.
 */
final class GenerationImageController
{
    public function __invoke(#[CurrentUser] User $user, string $generation, PixelforgeClient $client): Response
    {
        $generation = Generation::query()
            ->whereBelongsTo($user)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($generation);

        abort_unless($generation->canBeContinued(), 404);

        try {
            $image = $client->downloadImage((string) $generation->image_url);
        } catch (RequestException|ConnectionException) {
            abort(404);
        }

        $secondsLeft = max(0, (int) ceil(now()->diffInSeconds($generation->image_url_expires_at ?? now()->addDay())));

        return response($image, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="image-%d.png"', $generation->id),
            'Cache-Control' => sprintf('private, max-age=%d', min($secondsLeft, 86400)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
