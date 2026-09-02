<?php

declare(strict_types=1);

test('formats 404 not found errors using standardized json envelope', function (): void {
    $response = $this->getJson('/api/v1/non-existent-endpoint');

    $response->assertNotFound();
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'NOT_FOUND',
            'message' => 'The requested route or resource could not be found.',
        ],
    ]);
});

test('formats 405 method not allowed errors using standardized json envelope', function (): void {
    $response = $this->postJson('/api/v1/health');

    $response->assertStatus(405);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'The HTTP method is not allowed for this route.',
        ],
    ]);
});
