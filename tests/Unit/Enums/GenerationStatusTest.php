<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Enums\Pixelforge\GenerationStatus as PixelforgeStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Generation;

test('moves only forward, may skip steps, and never leaves a final state', function (): void {
    $table = [];

    foreach (GenerationStatus::cases() as $status) {
        $table[$status->value] = array_map(fn (GenerationStatus $next): string => $next->value, $status->transitions());
    }

    expect($table)->toBe([
        'pending' => ['queued', 'processing', 'succeeded', 'failed'],
        'queued' => ['processing', 'succeeded', 'failed'],
        'processing' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => [],
    ]);
});

test('groups statuses by what callers ask about', function (): void {
    expect(GenerationStatus::unfinished())->toBe([GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing]);
    expect(GenerationStatus::atProvider())->toBe([GenerationStatus::Queued, GenerationStatus::Processing]);
    expect(array_map(fn (GenerationStatus $status): bool => $status->isFinal(), GenerationStatus::cases()))->toBe([false, false, false, true, true]);
});

test("maps every one of Pixelforge's statuses", function (PixelforgeStatus $theirs, GenerationStatus $ours): void {
    expect(GenerationStatus::fromPixelforge($theirs))->toBe($ours);
})->with([
    [PixelforgeStatus::Queued, GenerationStatus::Queued],
    [PixelforgeStatus::Processing, GenerationStatus::Processing],
    [PixelforgeStatus::Succeeded, GenerationStatus::Succeeded],
    [PixelforgeStatus::Failed, GenerationStatus::Failed],
]);

test('refuses to save an illegal status change', function (): void {
    $generation = Generation::factory()->succeeded()->create();

    expect(fn () => $generation->update(['status' => GenerationStatus::Processing]))
        ->toThrow(InvalidStatusTransitionException::class, 'A generation cannot move from succeeded to processing.');
    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('saves a legal status change and other changes to a finished generation', function (): void {
    $generation = Generation::factory()->queued()->create();

    $generation->update(['status' => GenerationStatus::Failed]);
    $generation->update(['error_message' => 'Updated wording.']);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
});

test('lets only an unconfirmed failure be overturned by Pixelforge, and only forward', function (): void {
    $unconfirmed = array_values(array_filter(GenerationStatus::cases(), fn (GenerationStatus $next): bool => GenerationStatus::Failed->canTransitionTo($next, unconfirmedFailure: true)));

    expect($unconfirmed)->toBe([GenerationStatus::Queued, GenerationStatus::Processing, GenerationStatus::Succeeded]);
    expect(GenerationStatus::Failed->canTransitionTo(GenerationStatus::Succeeded))->toBeFalse();
    // The flag only matters for a failure: a success stays final.
    expect(GenerationStatus::Succeeded->canTransitionTo(GenerationStatus::Processing, unconfirmedFailure: true))->toBeFalse();
});

test('lets the model save the overturn of an unconfirmed failure, but no other way out of a failure', function (): void {
    $unconfirmed = Generation::factory()->create(['status' => GenerationStatus::Failed, 'error_code' => Generation::UNCONFIRMED_FAILURE, 'charge' => '0']);
    $confirmed = Generation::factory()->create(['status' => GenerationStatus::Failed, 'error_code' => 'content_policy_violation', 'charge' => '0']);

    $unconfirmed->update(['status' => GenerationStatus::Succeeded, 'error_code' => null]);

    expect($unconfirmed->refresh()->status)->toBe(GenerationStatus::Succeeded);
    expect(fn () => $confirmed->update(['status' => GenerationStatus::Succeeded]))
        ->toThrow(InvalidStatusTransitionException::class, 'A generation cannot move from failed to succeeded.');
});
