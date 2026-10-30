<?php

declare(strict_types=1);

use App\Internal\InternalSignature;

const SHARED_VECTOR_HEX = '723447fac31e5883463cdebeb32b961445f2ea9dd5877e8c2a5cf685f2f8abd7';

it('produces the shared cross-service test vector', function (): void {
    $hex = InternalSignature::compute('test-secret', 1700000000, 'POST', '/internal/v1/ping', '{"a":1}');

    expect($hex)->toBe(SHARED_VECTOR_HEX);
});

it('formats the header as t=<ts>,v1=<hex>', function (): void {
    $signature = new InternalSignature('test-secret');

    expect($signature->sign('post', '/internal/v1/ping', '{"a":1}', 1700000000))
        ->toBe('t=1700000000,v1='.SHARED_VECTOR_HEX);
});

it('verifies a fresh signature and rejects tampering', function (): void {
    $signature = new InternalSignature('test-secret', 300);
    $header = $signature->sign('POST', '/internal/v1/ping?x=1', '{"a":1}', 1700000000);

    expect($signature->verify($header, 'POST', '/internal/v1/ping?x=1', '{"a":1}', 1700000100))->toBeTrue()
        ->and($signature->verify($header, 'POST', '/internal/v1/ping?x=2', '{"a":1}', 1700000100))->toBeFalse()
        ->and($signature->verify($header, 'POST', '/internal/v1/ping?x=1', '{"a":2}', 1700000100))->toBeFalse()
        ->and($signature->verify($header, 'GET', '/internal/v1/ping?x=1', '{"a":1}', 1700000100))->toBeFalse();
});

it('rejects signatures outside the 300 second window', function (int $now, bool $expected): void {
    $signature = new InternalSignature('test-secret', 300);
    $header = $signature->sign('POST', '/internal/v1/ping', '{"a":1}', 1700000000);

    expect($signature->verify($header, 'POST', '/internal/v1/ping', '{"a":1}', $now))->toBe($expected);
})->with([
    'exactly at the edge (past)' => [1700000300, true],
    'one second too late' => [1700000301, false],
    'exactly at the edge (future)' => [1699999700, true],
    'one second too early' => [1699999699, false],
]);

it('rejects malformed headers and a wrong secret', function (?string $header): void {
    $signature = new InternalSignature('test-secret');

    expect($signature->verify($header, 'POST', '/internal/v1/ping', '{"a":1}', 1700000000))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [''],
    'no timestamp' => ['v1='.SHARED_VECTOR_HEX],
    'no signature' => ['t=1700000000'],
    'garbage' => ['totally-not-a-signature'],
    'wrong secret' => ['t=1700000000,v1='.hash_hmac('sha256', '1700000000.POST./internal/v1/ping.{"a":1}', 'other')],
]);

it('accepts any matching v1 during secret rotation', function (): void {
    $signature = new InternalSignature('test-secret');
    $header = 't=1700000000,v1='.str_repeat('0', 64).',v1='.SHARED_VECTOR_HEX;

    expect($signature->verify($header, 'POST', '/internal/v1/ping', '{"a":1}', 1700000000))->toBeTrue();
});

it('refuses to work without a secret', function (): void {
    new InternalSignature('');
})->throws(InvalidArgumentException::class);
