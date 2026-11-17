<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

test('estimates the exact charge range from pixelforge pricing plus the markup', function (): void {
    fakePixelforgePricing();

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('a', 40),
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertOk()->assertExactJson([
        'charge' => [
            'min' => ['amount' => '0.0581750000', 'formatted' => '$0.06'],
            'max' => ['amount' => '0.0863250000', 'formatted' => '$0.09'],
        ],
        'provider_cost' => ['min' => '0.046540000', 'max' => '0.069060000'],
        'tokens' => [
            'input_text' => ['min' => 8, 'max' => 12],
            'input_image' => ['min' => 0, 'max' => 0],
            'output' => ['min' => 1100, 'max' => 1100],
            'reasoning' => ['min' => 200, 'max' => 2000],
        ],
    ]);
});

test('fetches pricing once and reuses it for later estimates', function (): void {
    fakePixelforgePricing();
    $input = ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'];

    $this->postJson(route('generations.estimate'), $input)->assertOk();
    $this->postJson(route('generations.estimate'), $input)->assertOk();

    Http::assertSentCount(1);
});

test('rejects invalid estimate input', function (array $input, string $field): void {
    Http::fake();

    $response = $this->postJson(route('generations.estimate'), [
        'size' => '1024x1024',
        'quality' => 'medium',
        ...$input,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    Http::assertNothingSent();
})->with([
    'unsupported size' => [['size' => '512x512'], 'size'],
    'unsupported quality' => [['quality' => 'ultra'], 'quality'],
    'five reference images' => [['reference_images' => array_map(fn (int $i): string => "https://example.com/{$i}.png", range(1, 5))], 'reference_images'],
    'non-http reference image' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'duplicate reference images' => [['reference_images' => ['https://example.com/a.png', 'https://example.com/a.png']], 'reference_images.0'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
]);
