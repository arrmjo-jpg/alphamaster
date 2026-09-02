<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'healthy',
                'timestamp' => now()->toIso8601String(),
                'framework' => 'Laravel '.app()->version(),
            ],
        ]);
    })->name('api.health');
});
