<?php

declare(strict_types=1);

test('forces json response on api routes even when no accept header is sent', function (): void {
    $response = $this->get('/api/v1/health');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');
    $response->assertJsonStructure([
        'success',
        'data' => [
            'status',
            'timestamp',
            'framework',
        ],
    ]);
});
