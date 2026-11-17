<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Exceptions\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\Data\Generation;
use App\Services\Pixelforge\Data\GenerationRequest;
use App\Services\Pixelforge\Data\Pricing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Pixelforge image API. It does not retry or sleep; callers decide how to recover.
 */
final readonly class PixelforgeClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private int $timeout = 15,
        private int $connectTimeout = 5,
    ) {}

    /**
     * Queue a generation. Replaying the same idempotency key with the same request returns the existing generation.
     *
     * @throws PixelforgeException
     * @throws ConnectionException
     */
    public function createGeneration(GenerationRequest $request, string $idempotencyKey): Generation
    {
        $response = $this->api()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $request->toArray());

        return Generation::fromArray($this->successfulJson($response));
    }

    /**
     * @throws PixelforgeException
     * @throws ConnectionException
     */
    public function getGeneration(string $id): Generation
    {
        $response = $this->api()->get('generations/'.rawurlencode($id));

        return Generation::fromArray($this->successfulJson($response));
    }

    /**
     * The current published rates and token tables. This endpoint does not require an API key.
     *
     * @throws PixelforgeException
     * @throws ConnectionException
     */
    public function pricing(): Pricing
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->get('pricing');

        return Pricing::fromArray($this->successfulJson($response));
    }

    /**
     * Download a finished image from its signed URL. The URL is self-authenticating, so no API key is sent.
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function downloadImage(string $imageUrl): string
    {
        return Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->get($imageUrl)
            ->throw()
            ->body();
    }

    private function api(): PendingRequest
    {
        throw_if($this->apiKey === null || $this->apiKey === '', RuntimeException::class, 'The Pixelforge API key is not configured. Set PIXELFORGE_API_KEY.');

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws PixelforgeException
     */
    private function successfulJson(Response $response): array
    {
        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return (array) $response->json();
    }
}
