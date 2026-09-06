<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Settings Authorization
|--------------------------------------------------------------------------
|
| `settings.view` and `settings.update` have been in the permission catalogue
| and on the seeded roles since Phase 6, and until now nothing checked them: the
| admin settings routes carried the perimeter middleware only. Every account that
| cleared the perimeter could read and write every settings group, including
| `security`, `auth` and `rate_limit`.
|
| These assert against the seeded role matrix rather than synthetic roles, so they
| describe what an operator actually gets:
|
|   administrator  settings.view + settings.update
|   editor         settings.view
|   support        neither
|
*/

beforeEach(function (): void {
    Cache::flush();

    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * A valid update payload, so a refusal is authorization and never validation.
 *
 * @return array<string, mixed>
 */
function settingsPayload(): array
{
    return ['settings' => ['site_name' => 'Changed By Test']];
}

// ── Reading requires settings.view ───────────────────────────────────────────

test('an admin holding settings.view can read the settings index', function (): void {
    $this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/settings')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('an admin holding only settings.view can still read', function (): void {
    // editor holds settings.view and not settings.update.
    $this->withToken(adminToken(roles: ['editor']))
        ->getJson('/api/v1/admin/settings')
        ->assertOk();
});

test('an admin without settings.view cannot read the index', function (): void {
    $this->withToken(adminToken(roles: ['support']))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('an admin without settings.view cannot read one group', function (): void {
    $this->withToken(adminToken(roles: ['support']))
        ->getJson('/api/v1/admin/settings/general')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('an admin with no roles at all is refused, not admitted by default', function (): void {
    // Being an administrator is necessary and never sufficient (ADR 0014).
    $this->withToken(adminToken())
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

// ── Writing requires settings.update ─────────────────────────────────────────

test('an admin holding settings.update can update a group', function (): void {
    $this->withToken(adminToken(roles: ['administrator']))
        ->putJson('/api/v1/admin/settings/general', settingsPayload())
        ->assertOk();

    expect(setting('general.site_name'))->toBe('Changed By Test');
});

test('an admin holding settings.view but not settings.update cannot write', function (): void {
    // This is the case the gap made invisible: editor could read and also write.
    $before = setting('general.site_name');

    $this->withToken(adminToken(roles: ['editor']))
        ->putJson('/api/v1/admin/settings/general', settingsPayload())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');

    // Refused before the write, not after it.
    expect(setting('general.site_name'))->toBe($before)
        ->and(setting('general.site_name'))->not->toBe('Changed By Test');
});

test('an admin holding neither permission cannot write', function (): void {
    $before = setting('general.site_name');

    $this->withToken(adminToken(roles: ['support']))
        ->putJson('/api/v1/admin/settings/general', settingsPayload())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');

    expect(setting('general.site_name'))->toBe($before);
});

test('the security and rate limit groups are protected too, not only general', function (): void {
    // These are the groups the gap exposed that matter most: a support-tier admin
    // could previously alter the platform's own security posture.
    foreach (['security', 'auth', 'rate_limit'] as $group) {
        $this->withToken(adminToken(roles: ['support']))
            ->getJson('/api/v1/admin/settings/'.$group)
            ->assertStatus(403);

        $this->withToken(adminToken(roles: ['support']))
            ->putJson('/api/v1/admin/settings/'.$group, ['settings' => []])
            ->assertStatus(403);
    }
});

// ── The perimeter is unchanged ───────────────────────────────────────────────

test('an unauthenticated request is still 401, not 403', function (): void {
    // Authentication is refused before authorization; the new middleware must not
    // convert a missing token into a permission problem.
    $this->getJson('/api/v1/admin/settings')->assertStatus(401);
    $this->putJson('/api/v1/admin/settings/general', settingsPayload())->assertStatus(401);
});

test('a token without admin:access is still refused at the ability layer', function (): void {
    $this->withToken(adminToken(['user:access'], roles: ['administrator']))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('a non-admin account is still refused even at the perimeter', function (): void {
    $this->withToken(adminToken(['admin:access'], isAdmin: false))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('an administrator reaching settings through the real MFA path is admitted', function (): void {
    // adminWithRoles drives mandatory enrolment, so this proves the permission check
    // sits behind the MFA perimeter rather than beside it.
    $token = adminWithRoles($this, ['administrator'], 'settings-mfa@example.test')['token'];

    resetClient($this);
    $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk();
});

test('a support administrator is refused on that same real MFA path', function (): void {
    $token = adminWithRoles($this, ['support'], 'settings-mfa-weak@example.test')['token'];

    resetClient($this);
    $this->withToken($token)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

// ── The public surface is untouched ──────────────────────────────────────────

test('the public settings endpoints remain open and unauthenticated', function (): void {
    // The fix applies to the admin prefix only. A public read requires no token and
    // must not have acquired a permission requirement.
    $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/v1/settings/general')->assertOk();
});
