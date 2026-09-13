<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Services\MailRuntimeConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);

    // The environment's configuration, as a deployment ships it. Everything below is
    // about whether the console's settings replace it.
    config([
        'mail.default' => 'log',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'from-the-env.test', 'port' => 2525],
        'mail.from.address' => 'env@example.test',
        'mail.from.name' => 'From the environment',
        'mail.reply_to' => null,
    ]);
});

// The mail configuration an operator entered, applied to the mail the platform sends.
//
// Eleven settings described an SMTP connection and exactly one thing read them: the
// "send a test message" button. Everything else — email verification, notifications,
// announcements — went out through config/mail.php, which is env-driven and cannot be
// reached from the console. An operator could configure a mail server, watch the test
// message arrive, and still have every real message leave through whatever the
// deployment's environment file happened to say.

/** Configure mail through the settings engine, as the console would. */
function storedMailConfiguration(mixed $test, array $overrides = []): void
{
    $test->service->updateGroup('mail', array_merge([
        'enabled' => true,
        'transport' => 'smtp',
        'host' => 'smtp.operator.test',
        'port' => 2587,
        'encryption' => 'tls',
        'username' => 'postmaster',
        'from_address' => 'platform@operator.test',
        'from_name' => 'The Operator',
    ], $overrides));

    Cache::flush();
}

function applyStoredMail(): void
{
    app(MailRuntimeConfiguration::class)->apply();
}

test('the stored connection is the one the platform sends through', function (): void {
    storedMailConfiguration($this);
    applyStoredMail();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.operator.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(2587)
        ->and(config('mail.mailers.smtp.username'))->toBe('postmaster')
        ->and(config('mail.from.address'))->toBe('platform@operator.test')
        ->and(config('mail.from.name'))->toBe('The Operator');
});

test('the mailer the framework hands out is built from the stored connection', function (): void {
    storedMailConfiguration($this);

    // Not the configuration array: the object the rest of the platform actually gets
    // when it asks for a mailer, resolved the way a notification resolves one.
    $symfony = app('mail.manager')->mailer()->getSymfonyTransport();

    expect((string) $symfony)->toContain('smtp.operator.test');
});

test('a switched-off configuration leaves the deployment’s own alone', function (): void {
    storedMailConfiguration($this, ['enabled' => false]);
    applyStoredMail();

    // Off means "the platform has no opinion", not "send nothing". Suppressing mail is
    // MAIL_MAILER=log, which a deployment already has; quietly swallowing a password
    // reset is a worse failure than sending it from the wrong host.
    expect(config('mail.default'))->toBe('log')
        ->and(config('mail.mailers.smtp.host'))->toBe('from-the-env.test');
});

test('a half-configured connection is not applied over a working one', function (): void {
    storedMailConfiguration($this, ['host' => null]);
    applyStoredMail();

    // `mail.enabled` declares the host and the sender as its dependencies. Replacing a
    // working environment mailer with one that cannot connect is worse than not
    // applying at all.
    expect(config('mail.mailers.smtp.host'))->toBe('from-the-env.test');
});

test('“none” means no encryption rather than an encryption called none', function (): void {
    storedMailConfiguration($this, ['encryption' => 'none']);
    applyStoredMail();

    expect(config('mail.mailers.smtp.encryption'))->toBeNull();
});

test('a reply-to is applied when one is set, and absent when it is not', function (): void {
    storedMailConfiguration($this);
    applyStoredMail();

    expect(config('mail.reply_to'))->toBeNull();

    storedMailConfiguration($this, ['reply_to' => 'support@operator.test']);
    applyStoredMail();

    expect(config('mail.reply_to.address'))->toBe('support@operator.test');
});

test('a transport that opens no connection is configured without one', function (): void {
    storedMailConfiguration($this, ['transport' => 'log']);
    applyStoredMail();

    // Writing a host and credentials into a transport that never connects would be
    // describing a connection nobody opens.
    expect(config('mail.default'))->toBe('log')
        ->and(config('mail.mailers.log'))->toBe(['transport' => 'log']);
});

test('the outbound ceiling applies to mail as it does to every other vendor call', function (): void {
    $this->service->updateGroup('operations', ['provider_timeout_seconds' => 7]);
    storedMailConfiguration($this);
    Cache::flush();
    applyStoredMail();

    // An unreachable mail host must not hold a request open indefinitely (ADR 0017).
    expect(config('mail.mailers.smtp.timeout'))->toBe(7);
});

test('an unreadable settings store leaves the environment’s configuration standing', function (): void {
    storedMailConfiguration($this);

    // The failure a platform actually sees: the settings table is gone and the mailer
    // is still asked for. Failing closed here would take mail down with the settings
    // store, which is the same precedent the rate limiter and maintenance middleware
    // set.
    Cache::flush();
    Schema::dropIfExists('setting_translations');
    Schema::dropIfExists('setting_revisions');
    Schema::drop('settings');

    applyStoredMail();

    expect(config('mail.mailers.smtp.host'))->toBe('from-the-env.test');
});
