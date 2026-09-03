<?php

declare(strict_types=1);

use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
    $this->token = adminToken();
});

/**
 * @param  array<string, mixed>  $settings
 */
function putSettings(mixed $test, array $payload, string $group = 'general'): TestResponse
{
    return $test->withToken($test->token)->putJson('/api/v1/admin/settings/'.$group, $payload);
}

test('batch update requires a non-empty settings object', function (): void {
    putSettings($this, [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    putSettings($this, ['settings' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('batch update rejects a list payload whose keys are not setting identifiers', function (): void {
    putSettings($this, ['settings' => ['site_name', 'site_description']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('batch update rejects keys that are not lowercase identifiers', function (): void {
    foreach (['Site_Name', 'site name', 'site-name', 'site.name', '1site', ''] as $badKey) {
        putSettings($this, ['settings' => [$badKey => 'value']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
});

test('batch update rejects more keys than the batch limit', function (): void {
    $settings = [];
    for ($i = 0; $i < 101; $i++) {
        $settings['key_'.$i] = 'value';
    }

    putSettings($this, ['settings' => $settings])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('batch update rejects a value nested beyond the depth limit', function (): void {
    $deep = 'leaf';
    for ($i = 0; $i < 12; $i++) {
        $deep = ['level' => $deep];
    }

    putSettings($this, ['settings' => ['site_name' => $deep]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('batch update rejects an oversized string value', function (): void {
    putSettings($this, ['settings' => ['site_name' => str_repeat('a', 60001)]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('batch update accepts a well formed payload at the structural layer', function (): void {
    putSettings($this, ['settings' => ['site_name' => 'Perfectly Fine']])
        ->assertOk();

    expect(setting('general.site_name'))->toBe('Perfectly Fine');
});
