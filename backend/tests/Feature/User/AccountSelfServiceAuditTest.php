<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

/*
 * What an account does to itself is in the trail (ADR 0057 §3, extending ADR 0037): its
 * profile by field name, its password by the fact alone, its picture by media id, and its
 * second factor by method. Never a value, a password, a secret or a code.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);
    Storage::fake('local');
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: string}
 */
function selfServiceAccount(array $attributes = []): array
{
    $user = makeAccount(array_merge([
        'email' => 'self-service'.uniqid().'@example.test',
        'password' => TEST_ACCOUNT_PASSWORD,
    ], $attributes));

    return [$user, $user->createToken('session', [$user->isAdmin() ? 'admin:access' : 'user:access'])->plainTextToken];
}

/**
 * A real, decodable PNG over a real temp file, so the media validator reads actual bytes.
 */
function selfServicePng(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_self_');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk'
        .'+M+ACzDhVQGVUEsBAP7bA/1B9AAAAABJRU5ErkJggg=='
    ));

    return new UploadedFile($path, 'me.png', 'image/png', null, true);
}

function selfServiceRecords(string $action): mixed
{
    return AuditRecord::query()->where('action', $action)->get();
}

test('an administrator\'s own profile change is recorded by field name, never by value', function (): void {
    [$admin, $token] = selfServiceAccount(['name' => 'Before Name', 'account_type' => AccountType::ADMIN]);
    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->patchJson('/api/v1/profile', ['name' => 'Secretly Renamed', 'bio' => 'A private note'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Secretly Renamed');

    $record = selfServiceRecords('account.updated')->sole();

    expect($record->subject)->toBe($admin->id)
        ->and($record->context['changed'])->toEqualCanonicalizing(['name', 'bio'])
        ->and($record->context['by_account_holder'])->toBeTrue()
        ->and($record->context['email_verification_cleared'])->toBeFalse()
        ->and(json_encode($record->context))->not->toContain('Secretly')
        ->and(json_encode($record->context))->not->toContain('private note');
});

test('a profile save that moves nothing records nothing', function (): void {
    [, $token] = selfServiceAccount(['name' => 'Same Name']);
    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->patchJson('/api/v1/profile', ['name' => 'Same Name'])->assertOk();

    expect(selfServiceRecords('account.updated'))->toHaveCount(0);
});

test('a new number clears its confirmation, and the record says so without the number', function (): void {
    [$user, $token] = selfServiceAccount(['phone' => '+962790000111']);
    $user->forceFill(['phone_verified_at' => now()])->save();
    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->patchJson('/api/v1/profile', ['phone' => '+962790000222'])
        ->assertOk()
        ->assertJsonPath('data.phone_verified', false);

    $record = selfServiceRecords('account.updated')->sole();

    expect($record->context['changed'])->toBe(['phone'])
        ->and($record->context['phone_verification_cleared'])->toBeTrue()
        ->and(json_encode($record->context))->not->toContain('0000222')
        ->and(json_encode($record->context))->not->toContain('0000111');
});

test('changing a password is recorded, with its consequence and no trace of the password', function (): void {
    [$user, $token] = selfServiceAccount();
    $user->createToken('another-device', ['user:access']);
    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => TEST_ACCOUNT_PASSWORD,
        'password' => 'A-new-and-long-passphrase',
        'password_confirmation' => 'A-new-and-long-passphrase',
    ])->assertOk();

    $record = selfServiceRecords('account.password_changed')->sole();
    $encoded = (string) json_encode($record->context);

    expect($record->subject)->toBe($user->id)
        ->and($record->context)->toBe(['had_password' => true, 'other_sessions_revoked' => 1])
        ->and($encoded)->not->toContain('passphrase')
        ->and($encoded)->not->toContain(TEST_ACCOUNT_PASSWORD)
        ->and($encoded)->not->toContain('$2y$');
});

test('a wrong current password changes nothing and records nothing', function (): void {
    [, $token] = selfServiceAccount();
    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => 'not-the-password',
        'password' => 'A-new-and-long-passphrase',
        'password_confirmation' => 'A-new-and-long-passphrase',
    ])->assertStatus(422);

    expect(selfServiceRecords('account.password_changed'))->toHaveCount(0);
});

test('setting and removing a picture is recorded, and the session reads the picture', function (): void {
    [$user, $token] = selfServiceAccount(['account_type' => AccountType::ADMIN]);
    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.avatar_url', null);

    $this->withToken($token)
        ->post('/api/v1/profile/avatar', ['file' => selfServicePng()], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $media = MediaFile::query()->where('collection', 'avatar')->sole();
    $changed = selfServiceRecords('account.avatar_changed')->sole();

    expect($changed->subject)->toBe($user->id)
        ->and($changed->context)->toBe(['media_id' => $media->id])
        ->and(json_encode($changed->context))->not->toContain('me.png');

    resetClient($this);
    expect($this->withToken($token)->getJson('/api/v1/auth/me')->json('data.avatar_url'))->toBeString();

    $this->withToken($token)->deleteJson('/api/v1/profile/avatar')->assertNoContent();
    // Nothing left to remove: no second record.
    $this->withToken($token)->deleteJson('/api/v1/profile/avatar')->assertNoContent();

    expect(selfServiceRecords('account.avatar_removed'))->toHaveCount(1);

    resetClient($this);
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertJsonPath('data.avatar_url', null);
});

test('turning a second factor on and off is recorded by method, never by secret or code', function (): void {
    [$user, $token] = selfServiceAccount();
    AuditRecord::query()->getQuery()->delete();

    $secret = (string) $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->assertOk()->json('data.secret');

    $recovery = $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertOk()->json('data.recovery_codes');

    $enabled = selfServiceRecords('account.mfa_enabled')->sole();

    expect($enabled->subject)->toBe($user->id)
        ->and($enabled->context)->toBe(['method' => 'totp'])
        ->and(json_encode($enabled->context))->not->toContain($secret);

    $this->withToken($token)->deleteJson('/api/v1/auth/mfa', ['code' => $recovery[0]])->assertOk();

    $disabled = selfServiceRecords('account.mfa_disabled')->sole();

    expect($disabled->context)->toBe(['sessions_revoked' => false])
        ->and(json_encode($disabled->context))->not->toContain((string) $recovery[0]);
});

test('a refused disable records nothing', function (): void {
    [, $token] = selfServiceAccount();

    $secret = (string) $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->json('data.secret');
    $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertOk();

    $this->withToken($token)->deleteJson('/api/v1/auth/mfa', ['code' => '00000000'])->assertStatus(401);

    expect(selfServiceRecords('account.mfa_disabled'))->toHaveCount(0);
});
