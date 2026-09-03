<?php

declare(strict_types=1);

use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/api/v1/test-admin-perimeter', function () {
        return response()->json(['success' => true, 'message' => 'Admin access granted']);
    })->middleware(['api', 'admin']);
});

test('unauthenticated request to admin perimeter returns 401 unauthenticated', function (): void {
    $response = $this->getJson('/api/v1/test-admin-perimeter');

    $response->assertStatus(401);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'UNAUTHENTICATED',
        ],
    ]);
});

test('admin perimeter fails closed: user without administrative identity is denied 403', function (): void {
    // A model that cannot answer the account-type question at all.
    $regularUser = new class extends Illuminate\Foundation\Auth\User
    {
        protected $table = 'users';
    };

    $response = $this->actingAs($regularUser)
        ->getJson('/api/v1/test-admin-perimeter');

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'ADMIN_ACCESS_REQUIRED',
            'message' => 'Administrative privileges are required to access this endpoint.',
        ],
    ]);
});

test('admin perimeter denies an account whose type is user', function (): void {
    $user = new class extends Illuminate\Foundation\Auth\User
    {
        public function isAdmin(): bool
        {
            return false;
        }
    };

    $response = $this->actingAs($user)
        ->getJson('/api/v1/test-admin-perimeter');

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'ADMIN_ACCESS_REQUIRED',
        ],
    ]);
});

test('admin perimeter permits an account whose type is admin', function (): void {
    $adminUser = new class extends Illuminate\Foundation\Auth\User
    {
        public function isAdmin(): bool
        {
            return true;
        }
    };

    $response = $this->actingAs($adminUser)
        ->getJson('/api/v1/test-admin-perimeter');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'message' => 'Admin access granted',
    ]);
});

test('admin perimeter denies an account carrying a role but not the admin type', function (): void {
    // A role says what an administrator may do; it is never what makes one. An
    // account of type user must be refused however many role relations it holds.
    $roleUser = new class extends Illuminate\Foundation\Auth\User
    {
        public function isAdmin(): bool
        {
            return false;
        }

        public function hasRole(string $role): bool
        {
            return $role === 'admin';
        }
    };

    $this->actingAs($roleUser)
        ->getJson('/api/v1/test-admin-perimeter')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ADMIN_ACCESS_REQUIRED');
});
