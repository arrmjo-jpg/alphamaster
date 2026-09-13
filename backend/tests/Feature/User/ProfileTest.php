<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use App\Modules\User\Models\UserProfileLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * The signed-in account's own profile (ADR 0051 §4, §5).
 */

uses(RefreshDatabase::class);

const PROFILE = '/api/v1/profile';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function profileAccount(array $attributes = []): array
{
    $user = makeAccount(array_merge(['email' => 'profile'.uniqid().'@example.test'], $attributes));

    return [$user, $user->createToken('session', [$user->isAdmin() ? 'admin:access' : 'user:access'])->plainTextToken];
}

test('the profile is the caller\'s own, and carries no credential', function (): void {
    [$user, $token] = profileAccount(['name' => 'Profile Owner']);

    $body = $this->withToken($token)->getJson(PROFILE)
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Profile Owner')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.avatar_url', null)
        ->assertJsonPath('data.links', [])
        ->content();

    expect($body)->not->toContain('"password"')->and($body)->not->toContain('phone_hash');
});

test('the profile requires a signed-in account', function (): void {
    $this->getJson(PROFILE)->assertStatus(401);
});

test('name, bio, language and location are changed, and the location is stamped', function (): void {
    [$user, $token] = profileAccount();

    $this->withToken($token)->patchJson(PROFILE, [
        'name' => 'Renamed',
        'bio' => 'Hello.',
        'preferred_locale' => 'ar',
        'country_code' => 'jo',
        'region' => 'Amman Governorate',
        'city' => 'Amman',
        'latitude' => 31.9539,
        'longitude' => 35.9106,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.location.country_code', 'JO')
        ->assertJsonPath('data.location.latitude', 31.9539)
        ->assertJsonPath('data.location.longitude', 35.9106);

    $fresh = $user->fresh();

    expect($fresh->bio)->toBe('Hello.')
        ->and($fresh->preferred_locale)->toBe('ar')
        ->and($fresh->location_updated_at)->not->toBeNull();
});

test('a location that is not a place is refused', function (array $payload, string $field): void {
    [, $token] = profileAccount();

    $this->withToken($token)->patchJson(PROFILE, $payload)
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'latitude without longitude' => [['latitude' => 10], 'longitude'],
    'longitude without latitude' => [['longitude' => 10], 'latitude'],
    'latitude out of range' => [['latitude' => 91, 'longitude' => 0], 'latitude'],
    'longitude out of range' => [['latitude' => 0, 'longitude' => 181], 'longitude'],
    'country code not two letters' => [['country_code' => 'JOR'], 'country_code'],
    'unknown language' => [['preferred_locale' => 'zz'], 'preferred_locale'],
]);

test('a user\'s new address clears its verification', function (): void {
    [$user, $token] = profileAccount();

    $this->withToken($token)->patchJson(PROFILE, ['email' => 'Moved@Example.test'])
        ->assertOk()->assertJsonPath('data.email', 'moved@example.test')->assertJsonPath('data.email_verified', false);

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull();
});

test('an administrator\'s address is not changed from the profile', function (): void {
    [$admin, $token] = profileAccount(['account_type' => AccountType::ADMIN]);
    $original = $admin->email;

    $this->withToken($token)->patchJson(PROFILE, ['email' => 'elsewhere@example.test'])
        ->assertStatus(403)->assertJsonPath('error.code', 'PROFILE_EMAIL_MANAGED');

    expect($admin->fresh()->email)->toBe($original);
});

test('a new number clears its verification, and a number in use is refused', function (): void {
    [$user, $token] = profileAccount();
    $user->phone = '+962790000301';
    $user->phone_verified_at = now();
    $user->save();

    $other = makeAccount(['email' => 'number-owner@example.test']);
    $other->phone = '+962790000302';
    $other->save();

    $this->withToken($token)->patchJson(PROFILE, ['phone' => '+962 79 000 0302'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['phone']]]);

    $this->withToken($token)->patchJson(PROFILE, ['phone' => '+962790000303'])
        ->assertOk()->assertJsonPath('data.phone', '+962790000303')->assertJsonPath('data.phone_verified', false);
});

test('the only way into an account cannot be removed', function (): void {
    [$user, $token] = profileAccount();
    DB::table('users')->where('id', $user->id)->update(['email' => null, 'password' => null, 'phone' => '+962790000304', 'phone_hash' => 'x']);

    $this->withToken($token)->patchJson(PROFILE, ['phone' => null])
        ->assertStatus(409)->assertJsonPath('error.code', 'LAST_SIGN_IN_METHOD');
});

test('a password is set without a current one, changed only with the right one, and other sessions end', function (): void {
    [$user, $token] = profileAccount();
    DB::table('users')->where('id', $user->id)->update(['password' => null]);
    $user->createToken('another-session', ['user:access']);

    $this->withToken($token)->putJson(PROFILE.'/password', [
        'password' => 'first-new-password',
        'password_confirmation' => 'first-new-password',
    ])->assertOk();

    expect(Hash::check('first-new-password', (string) $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);

    $this->withToken($token)->putJson(PROFILE.'/password', [
        'current_password' => 'not-it',
        'password' => 'second-new-password',
        'password_confirmation' => 'second-new-password',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['current_password']]]);

    $this->withToken($token)->putJson(PROFILE.'/password', [
        'current_password' => 'first-new-password',
        'password' => 'second-new-password',
        'password_confirmation' => 'second-new-password',
    ])->assertOk();

    expect(Hash::check('second-new-password', (string) $user->fresh()->password))->toBeTrue();
});

test('links replace the whole list, in order', function (): void {
    [$user, $token] = profileAccount();

    $this->withToken($token)->putJson(PROFILE.'/links', ['links' => [
        ['platform' => 'website', 'url' => 'https://person.example.test'],
        ['platform' => 'instagram', 'url' => 'https://instagram.example.test/person'],
    ]])->assertOk()
        ->assertJsonPath('data.links.0.platform', 'website')
        ->assertJsonPath('data.links.1.platform', 'instagram')
        ->assertJsonPath('data.links.1.position', 1);

    $this->withToken($token)->putJson(PROFILE.'/links', ['links' => [
        ['platform' => 'x', 'url' => 'https://x.example.test/person'],
    ]])->assertOk()->assertJsonCount(1, 'data.links');

    $this->withToken($token)->putJson(PROFILE.'/links', ['links' => []])->assertOk()->assertJsonPath('data.links', []);

    expect(UserProfileLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('a link must be a known platform and a safe address, and there are at most ten', function (array $links, string $field): void {
    [, $token] = profileAccount();

    $this->withToken($token)->putJson(PROFILE.'/links', ['links' => $links])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown platform' => [[['platform' => 'myspace', 'url' => 'https://example.test']], 'links.0.platform'],
    'javascript address' => [[['platform' => 'website', 'url' => 'javascript:alert(1)']], 'links.0.url'],
    'credentials in the address' => [[['platform' => 'website', 'url' => 'https://user:pw@example.test']], 'links.0.url'],
    'eleven links' => [array_fill(0, 11, ['platform' => 'website', 'url' => 'https://example.test']), 'links'],
]);

test('changing one profile leaves every other account alone', function (): void {
    [, $token] = profileAccount();
    $bystander = makeAccount(['email' => 'bystander@example.test', 'name' => 'Bystander']);

    $this->withToken($token)->patchJson(PROFILE, ['name' => 'Changed', 'id' => $bystander->id])->assertOk();

    expect($bystander->fresh()->name)->toBe('Bystander');
});
