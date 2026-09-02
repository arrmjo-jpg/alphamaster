<?php

declare(strict_types=1);

test('generates and attaches a valid ULID request_id when none is provided', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk();
    $response->assertHeader('X-Request-ID');

    $requestId = $response->headers->get('X-Request-ID');
    expect($requestId)->toBeString()->toHaveLength(26);
});

test('preserves incoming X-Request-ID header in response and context', function (): void {
    $customId = 'REQ-TEST-CUSTOM-12345';

    $response = $this->withHeader('X-Request-ID', $customId)
        ->getJson('/api/v1/health');

    $response->assertOk();
    $response->assertHeader('X-Request-ID', $customId);
});
