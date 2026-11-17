<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandlePixelforgeWebhook;
use App\Exceptions\Pixelforge\InvalidWebhookSignatureException;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;

final class PixelforgeWebhookController
{
    public function __invoke(Request $request, WebhookSignatureVerifier $verifier, HandlePixelforgeWebhook $handle): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $verifier->verify($payload, $request->header('Pixelforge-Signature'));
        } catch (InvalidWebhookSignatureException $invalidWebhookSignatureException) {
            Log::warning('Rejected a Pixelforge webhook: '.$invalidWebhookSignatureException->getMessage(), [
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'event_type' => $request->header('Pixelforge-Event-Type'),
                'delivery_attempt' => $request->header('Pixelforge-Delivery-Attempt'),
            ]);

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
