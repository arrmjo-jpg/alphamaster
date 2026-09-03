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
    // Plain user model with neither is_admin nor hasRole method
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

test('admin perimeter denies user with is_admin set to false', function (): void {
    $user = new class extends Illuminate\Foundation\Auth\User
    {
        public $is_admin = false;
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

test('admin perimeter permits user with is_admin set to true', function (): void {
    $adminUser = new class extends Illuminate\Foundation\Auth\User
    {
        public $is_admin = true;
    };

    $response = $this->actingAs($adminUser)
        ->getJson('/api/v1/test-admin-perimeter');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'message' => 'Admin access granted',
    ]);
});

test('admin perimeter denies a user carrying only a role, with no is_admin flag', function (): void {
    // The perimeter no longer consults hasRole(): Spatie RBAC is not built, so a
    // role claim is unverifiable and must not grant administrative access.
    $roleUser = new class extends Illuminate\Foundation\Auth\User
    {
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
