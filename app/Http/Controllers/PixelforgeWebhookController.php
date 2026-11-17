<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandlePixelforgeWebhook;
use App\Exceptions\Pixelforge\InvalidWebhookSignatureException;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

final class PixelforgeWebhookController
{
    public function __invoke(Request $request, WebhookSignatureVerifier $verifier, HandlePixelforgeWebhook $handle): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $verifier->verify($payload, $request->header('Pixelforge-Signature'));
        } catch (InvalidWebhookSignatureException $invalidWebhookSignatureException) {
            return response()->json(['error' => $invalidWebhookSignatureException->getMessage()], 400);
        }

        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'The payload is not valid JSON.'], 400);
        }

        $processed = $handle->handle(is_array($event) ? $event : []);

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
