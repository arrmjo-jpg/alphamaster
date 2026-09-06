<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Secrets\MailPasswordVerifier;
use App\Modules\Settings\Secrets\SecretVerification;
use App\Modules\Settings\Services\MailConfigurationTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * The one verifier the platform actually ships.
 *
 * The rotation suite fakes the verifier, because it is testing the rotation contract
 * rather than SMTP — which means nothing there exercises this class, and a fault in it
 * would look exactly like a passing suite. That is the gap these tests close.
 */
beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->settings = app(SettingServiceInterface::class);

    // A configuration complete enough to attempt delivery, so the prerequisite check
    // is not what these tests end up measuring.
    $this->settings->updateGroup('mail', [
        'enabled' => true,
        'host' => 'smtp.example.test',
        'from_address' => 'noreply@example.test',
        'test_recipient' => 'operator@example.test',
        'username' => 'mailer',
    ]);
    $this->settings->set('mail', 'password', 'the-stored-credential');
});

test('a candidate is what gets tried, not the credential already stored', function (): void {
    Mail::fake();

    app(MailConfigurationTester::class)->test(['mail.password' => 'the-candidate-credential']);

    // The runtime mailer is built from the override. Were it built from storage, every
    // rotation would verify the credential it was replacing — passing always, proving
    // nothing, and failing invisibly.
    expect(config('mail.mailers.settings_test.password'))->toBe('the-candidate-credential');
});

test('without an override the stored credential is what gets tried', function (): void {
    Mail::fake();

    app(MailConfigurationTester::class)->test();

    expect(config('mail.mailers.settings_test.password'))->toBe('the-stored-credential');
});

test('an override is not remembered between calls', function (): void {
    Mail::fake();

    $tester = app(MailConfigurationTester::class);

    $tester->test(['mail.password' => 'the-candidate-credential']);
    $tester->test();

    // ADR 0038: no pending state. A candidate that survived its call would be a
    // credential held somewhere it is not yet in use.
    expect(config('mail.mailers.settings_test.password'))->toBe('the-stored-credential');
});

test('the verifier reports verified when delivery succeeds', function (): void {
    Mail::fake();

    $result = app(MailPasswordVerifier::class)->verify('the-candidate-credential');

    expect($result->status)->toBe(SecretVerification::VERIFIED);
});

test('the verifier reports failed, with a token rather than a message', function (): void {
    // A real transport against a host that does not exist. The point is not the
    // specific failure but that the verifier turns it into a short token: a transport
    // exception's message can carry the host, the username, and occasionally the
    // credential it just tried.
    $this->settings->set('mail', 'host', 'no-such-host.invalid');

    $result = app(MailPasswordVerifier::class)->verify('the-candidate-credential');

    expect($result->status)->toBe(SecretVerification::FAILED)
        ->and($result->detail)->not->toBeNull()
        ->and($result->detail)->not->toContain('the-candidate-credential')
        ->and($result->detail)->not->toContain('no-such-host.invalid')
        ->and($result->detail)->not->toContain('mailer');
})->skip(fn (): bool => getenv('CI') !== false, 'Attempts a real DNS lookup.');

test('an incomplete configuration blocks the rotation rather than passing it', function (): void {
    $this->settings->set('mail', 'host', null);

    $result = app(MailPasswordVerifier::class)->verify('the-candidate-credential');

    // Not "unavailable": a verifier exists and was asked. It could not confirm the
    // credential, and the platform does not commit what it could not verify.
    expect($result->status)->toBe(SecretVerification::FAILED)
        ->and($result->permitsCommit())->toBeFalse()
        ->and($result->detail)->toBe('incomplete');
});

test('the verifier speaks for exactly one credential', function (): void {
    expect(app(MailPasswordVerifier::class)->reference())->toBe('mail.password');
});
