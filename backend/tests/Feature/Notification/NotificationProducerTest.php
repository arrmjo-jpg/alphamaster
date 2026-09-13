<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Core\Contracts\PlatformNotifierContract;
use App\Modules\Core\Translation\Phrase;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Contracts\TemplateRendererContract;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Notifications\TemplatedNotification;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use PragmaRX\Google2FA\Google2FA;

/**
 * Producers: the modules that have something to tell an account about, raising it
 * through Core because none of them may import the Notification module (M3 decision 1).
 *
 * `security.alert` and `account.updated` were seeded, templated and translated from the
 * start and never raised. These tests prove each occasion now raises its notification,
 * that nothing is raised for an occasion that did not happen, and that the seam's one
 * weakness — a type written as a string — is closed by reading every producer.
 */
uses(RefreshDatabase::class);

const PRODUCER_PASSWORD = 'producer-account-password';

beforeEach(function (): void {
    Cache::flush();

    // Notifications are ShouldQueue and the container exports QUEUE_CONNECTION=redis;
    // see NotificationCenterTest for why this has to be set here.
    config(['queue.default' => 'sync']);

    // The container's mailer is `log`, which writes every delivered message to stderr.
    Mail::fake();

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);

    $this->account = makeAccount([
        'name' => 'Producer Account',
        'email' => 'producer@example.com',
        'password' => PRODUCER_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);
});

/**
 * The translation key a notification's `event` placeholder carries, if it is a Phrase.
 */
function eventPhrase(TemplatedNotification $notification): ?string
{
    $event = $notification->placeholders['event'] ?? null;

    return $event instanceof Phrase ? $event->key : null;
}

/**
 * Sign in, enrol TOTP and confirm it, returning what disabling it needs.
 *
 * @return array{token: string, recovery: array<int, string>}
 */
function enrolProducerAccount(mixed $test): array
{
    $token = $test->postJson('/api/v1/auth/login', [
        'identifier' => 'producer@example.com',
        'password' => PRODUCER_PASSWORD,
    ])->json('data.token');

    $secret = $test->withToken($token)
        ->postJson('/api/v1/auth/mfa/enrol')
        ->assertOk()
        ->json('data.secret');

    $recovery = $test->withToken($token)
        ->postJson('/api/v1/auth/mfa/verify', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()
        ->json('data.recovery_codes');

    return ['token' => $token, 'recovery' => $recovery];
}

// ── The seam ─────────────────────────────────────────────────────────────────

test('the platform notifier raises the named type for an account and greets it by name', function (): void {
    NotificationFacade::fake();

    app(PlatformNotifierContract::class)->notify($this->account, 'security.alert', ['event' => 'a literal event']);

    NotificationFacade::assertSentTo(
        $this->account,
        TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => $n->type === NotificationType::SECURITY_ALERT
            && $n->placeholders['name'] === 'Producer Account'
            && $n->placeholders['event'] === 'a literal event'
    );
});

test('a name the producer supplies is kept', function (): void {
    NotificationFacade::fake();

    app(PlatformNotifierContract::class)->notify($this->account, 'account.updated', ['name' => 'Chosen Name']);

    NotificationFacade::assertSentTo(
        $this->account,
        TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => $n->placeholders['name'] === 'Chosen Name'
    );
});

test('a type that names nothing is reported, and nothing is sent', function (): void {
    NotificationFacade::fake();
    Exceptions::fake();

    app(PlatformNotifierContract::class)->notify($this->account, 'security.alrt');

    NotificationFacade::assertNothingSent();
    Exceptions::assertReported(
        fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'security.alrt')
    );
});

test('a recipient that is not an account is reported, and nothing is sent', function (): void {
    NotificationFacade::fake();
    Exceptions::fake();

    app(PlatformNotifierContract::class)->notify(new stdClass, 'security.alert');

    NotificationFacade::assertNothingSent();
    Exceptions::assertReported(InvalidArgumentException::class);
});

test('a phrase is written in the recipient\'s language, not the producer\'s', function (): void {
    Mail::fake();

    $reader = makeAccount([
        'name' => 'Arabic Reader',
        'email' => 'arabic-reader@example.com',
        'preferred_locale' => 'ar',
        'account_type' => AccountType::USER,
    ]);

    // The producer is working in English.
    app()->setLocale('en');

    app(PlatformNotifierContract::class)->notify($reader, 'security.alert', [
        'event' => new Phrase('notifications.event.mfa_disabled'),
    ]);

    $stored = (string) DB::table('notifications')->where('notifiable_id', $reader->id)->value('data');
    $body = (string) (json_decode($stored, true)['body'] ?? '');

    expect($body)->toContain(__('notifications.event.mfa_disabled', [], 'ar'))
        ->and($body)->toContain('Arabic Reader')
        ->and($body)->not->toContain(__('notifications.event.mfa_disabled', [], 'en'))
        ->and(__('notifications.event.mfa_disabled', [], 'ar'))->not->toBe('notifications.event.mfa_disabled');
});

test('a notification that fails to deliver never fails the operation that raised it', function (): void {
    // A sync queue runs delivery inside the request, so a renderer that throws is a
    // throw inside the administrator's edit — after it committed.
    $this->mock(TemplateRendererContract::class, function ($mock): void {
        $mock->shouldReceive('render')->andThrow(new RuntimeException('the template store is unavailable'));
    });
    Exceptions::fake();

    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->putJson("/api/v1/admin/users/{$this->account->id}", ['name' => 'Edited Regardless'])
        ->assertOk();

    expect($this->account->refresh()->name)->toBe('Edited Regardless');
    Exceptions::assertReported(
        fn (RuntimeException $e): bool => $e->getMessage() === 'the template store is unavailable'
    );
});

test('every phrase a producer uses exists in both languages', function (): void {
    $phrases = [
        'notifications.event.mfa_method_added',
        'notifications.event.mfa_disabled',
        'notifications.event.account_details_changed',
        'notifications.event.account_address_changed',
    ];

    foreach (['en', 'ar'] as $locale) {
        foreach ($phrases as $phrase) {
            expect((new Phrase($phrase))->in($locale))->not->toBe($phrase, "{$phrase} has no {$locale} text");
        }
    }
});

// ── security.alert: second-factor changes ────────────────────────────────────

test('adding a second factor tells the account holder, once', function (): void {
    NotificationFacade::fake();

    $token = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'producer@example.com',
        'password' => PRODUCER_PASSWORD,
    ])->json('data.token');

    $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->assertOk()->json('data.secret');

    // Starting enrolment changes nothing about who can sign in.
    NotificationFacade::assertNothingSent();

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/verify', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk();

    NotificationFacade::assertSentTo(
        $this->account,
        TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => $n->type === NotificationType::SECURITY_ALERT
            && eventPhrase($n) === 'notifications.event.mfa_method_added'
    );
    NotificationFacade::assertCount(1);
});

test('a confirmation that fails tells nobody', function (): void {
    NotificationFacade::fake();

    $token = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'producer@example.com',
        'password' => PRODUCER_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->assertOk();
    $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);

    NotificationFacade::assertNothingSent();
});

test('turning the second factor off tells the account holder', function (): void {
    $enrolled = enrolProducerAccount($this);

    NotificationFacade::fake();

    $this->withToken($enrolled['token'])
        ->deleteJson('/api/v1/auth/mfa', ['code' => $enrolled['recovery'][0]])
        ->assertOk();

    NotificationFacade::assertSentTo(
        $this->account,
        TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => $n->type === NotificationType::SECURITY_ALERT
            && eventPhrase($n) === 'notifications.event.mfa_disabled'
    );
    NotificationFacade::assertCount(1);
});

test('a refused attempt to turn the second factor off tells nobody', function (): void {
    $enrolled = enrolProducerAccount($this);

    NotificationFacade::fake();

    $this->withToken($enrolled['token'])
        ->deleteJson('/api/v1/auth/mfa', ['code' => '000000'])
        ->assertStatus(401);

    NotificationFacade::assertNothingSent();
});

// ── account.updated: an administrator editing an account ─────────────────────

test('an administrator editing an account tells its owner, and only its owner', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    NotificationFacade::fake();

    $this->withToken($token)
        ->putJson("/api/v1/admin/users/{$this->account->id}", ['name' => 'Renamed Account'])
        ->assertOk();

    NotificationFacade::assertSentTo(
        $this->account,
        TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => $n->type === NotificationType::ACCOUNT_UPDATED
            && eventPhrase($n) === 'notifications.event.account_details_changed'
            && $n->placeholders['name'] === 'Renamed Account'
    );
    NotificationFacade::assertCount(1);
});

test('an address change says the new address needs confirming', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    NotificationFacade::fake();

    $this->withToken($token)
        ->putJson("/api/v1/admin/users/{$this->account->id}", ['email' => 'moved@example.com'])
        ->assertOk();

    NotificationFacade::assertSentTo(
        $this->account,
        TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => eventPhrase($n) === 'notifications.event.account_address_changed'
    );
});

test('a save that changes nothing tells nobody', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    NotificationFacade::fake();

    $this->withToken($token)
        ->putJson("/api/v1/admin/users/{$this->account->id}", ['name' => 'Producer Account'])
        ->assertOk();

    NotificationFacade::assertNothingSent();
});

// ── The guard ────────────────────────────────────────────────────────────────

test('every producer names a real notification type, as a literal', function (): void {
    $types = NotificationType::values();
    $calls = 0;

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = $file->getContents();
        $occurrences = substr_count($source, 'notifier->notify(');

        if ($occurrences === 0) {
            continue;
        }

        preg_match_all("/notifier->notify\\(\\s*[^,;]+?,\\s*'([^']*)'/", $source, $matches);

        // A variable, a constant or a concatenation would pass the type checker and
        // this test alike, so none is allowed: the literal is what is being checked.
        expect($matches[1])->toHaveCount(
            $occurrences,
            $file->getRelativePathname().' passes a notification type that is not a string literal'
        );

        foreach ($matches[1] as $type) {
            expect(in_array($type, $types, true))->toBeTrue(
                "{$file->getRelativePathname()} raises [{$type}], which is not a notification type"
            );
        }

        $calls += $occurrences;
    }

    // Guards the guard: a pattern that matched nothing would pass vacuously.
    expect($calls)->toBeGreaterThanOrEqual(3);
});
