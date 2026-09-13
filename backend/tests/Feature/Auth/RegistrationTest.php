<?php

declare(strict_types=1);

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Public registration with an email address and a password (ADR 0051 §3).
 */

uses(RefreshDatabase::class);

const REGISTER = '/api/v1/auth/register';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Person',
        'email' => 'New.Person@Example.test',
        'password' => 'a-good-long-password',
        'password_confirmation' => 'a-good-long-password',
    ], $overrides);
}

test('registration opens a user account, sends a verification link and signs the person in', function (): void {
    $response = $this->postJson(REGISTER, registration(['account_type' => 'admin']));

    $response->assertStatus(201)
        ->assertJsonPath('data.abilities', ['user:access'])
        ->assertJsonPath('data.email_verification_sent', true);

    $user = User::query()->where('email', 'new.person@example.test')->firstOrFail();

    expect($user->account_type)->toBe(AccountType::USER)
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('a-good-long-password', (string) $user->password))->toBeTrue()
        ->and(PersonalAccessToken::findToken((string) $response->json('data.token'))?->tokenable_id)->toBe($user->id);

    Notification::assertCount(1);

    $record = DB::table('audit_records')->where('action', AuditAction::ACCOUNT_CREATED)->first();

    expect(json_decode((string) $record?->context, true))->toBe(['active' => true, 'source' => 'registration']);
});

test('a number given at registration is stored unverified', function (): void {
    $this->postJson(REGISTER, registration(['phone' => '+962 79 000 0200']))->assertStatus(201);

    $user = User::query()->where('email', 'new.person@example.test')->firstOrFail();

    expect($user->phone)->toBe('+962790000200')->and($user->phone_verified_at)->toBeNull();
});

test('registration is refused while it is closed', function (): void {
    app(SettingServiceInterface::class)->set('auth', 'registration_enabled', false);
    Cache::flush();

    $this->postJson(REGISTER, registration())
        ->assertStatus(403)->assertJsonPath('error.code', 'REGISTRATION_CLOSED');

    expect(User::query()->count())->toBe(0);
});

test('an address or a number already in use is refused', function (): void {
    $existing = makeAccount(['email' => 'new.person@example.test']);
    $existing->phone = '+962790000201';
    $existing->save();

    $this->postJson(REGISTER, registration())->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['details' => ['email']]]);

    $this->postJson(REGISTER, registration(['email' => 'other@example.test', 'phone' => '+962 79 000 0201']))
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['phone']]]);
});

test('the password follows the configured minimum and must be confirmed', function (): void {
    $this->postJson(REGISTER, registration(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['password']]]);

    $this->postJson(REGISTER, registration(['password_confirmation' => 'something-else-entirely']))
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['password']]]);
});

test('registration is throttled per caller, however the email varies', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(REGISTER, registration(['email' => "person{$i}@example.test"]))->assertStatus(201);
        resetClient($this);
    }

    $this->postJson(REGISTER, registration(['email' => 'person-six@example.test']))
        ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
});
