<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\InvalidWebhookSignatureException;
use App\Services\Pixelforge\WebhookSignatureVerifier;

beforeEach(function (): void {
    config()->set('services.pixelforge.webhook_secret', 'whsec_test');
});

test('accepts a payload signed with the secret within the tolerance window', function (): void {
    $payload = '{"id":"evt_1","type":"generation.succeeded"}';
    $timestamp = now()->subMinutes(4)->getTimestamp();
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'));

    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify($payload, $header))
        ->not->toThrow(InvalidWebhookSignatureException::class);
});

test('accepts the payload when any of several v1 signatures matches', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = now()->getTimestamp();
    $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('0', 64), hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'));

    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify($payload, $header))
        ->not->toThrow(InvalidWebhookSignatureException::class);
});

test('rejects a payload that was changed after signing', function (): void {
    $timestamp = now()->getTimestamp();
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.{"amount":"1.00"}', 'whsec_test'));

    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify('{"amount":"9.00"}', $header))
        ->toThrow(InvalidWebhookSignatureException::class, 'The Pixelforge webhook signature does not match the payload.');
});

test('rejects a signature made with a different secret', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = now()->getTimestamp();
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_other'));

    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify($payload, $header))
        ->toThrow(InvalidWebhookSignatureException::class, 'The Pixelforge webhook signature does not match the payload.');
});

test('rejects a correctly signed payload whose timestamp is outside the tolerance window', function (int $secondsFromNow): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = now()->getTimestamp() + $secondsFromNow;
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'));

    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify($payload, $header))
        ->toThrow(InvalidWebhookSignatureException::class, 'The Pixelforge webhook timestamp is outside the tolerance window.');
})->with([
    'replayed after six minutes' => [-360],
    'dated six minutes ahead' => [360],
]);

test('rejects a missing or malformed signature header', function (?string $header): void {
    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify('{}', $header))
        ->toThrow(InvalidWebhookSignatureException::class, 'The Pixelforge-Signature header is missing or malformed.');
})->with([
    'missing' => [null],
    'empty' => [''],
    'non-numeric timestamp' => ['t=abc,v1=deadbeef'],
    'no signature' => ['t=1700000000'],
    'no timestamp' => ['v1=deadbeef'],
]);

test('fails closed when no signing secret is configured', function (): void {
    config()->set('services.pixelforge.webhook_secret');
    $timestamp = now()->getTimestamp();
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.{}', ''));

    expect(fn () => resolve(WebhookSignatureVerifier::class)->verify('{}', $header))
        ->toThrow(InvalidWebhookSignatureException::class, 'The Pixelforge webhook signing secret is not configured.');
});
