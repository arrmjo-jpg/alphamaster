<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Contracts\TemplateRendererContract;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Exceptions\MissingTemplateException;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Notification\Notifications\TemplatedNotification;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    // Notifications are ShouldQueue. The container exports QUEUE_CONNECTION=redis and a
    // real environment variable beats phpunit.xml (ADR 0027), so without this the jobs
    // would be pushed to Redis and never run inside the test — the assertions about
    // what was delivered would then be measuring nothing. That notifications really are
    // queued, and on which queue, is asserted separately with Queue::fake().
    config(['queue.default' => 'sync']);

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);

    $this->notifier = app(NotifierContract::class);
    $this->preferences = app(PreferenceResolverContract::class);
    $this->renderer = app(TemplateRendererContract::class);

    $this->user = makeAccount([
        'name' => 'Recipient',
        'email' => 'recipient@example.com',
        'account_type' => AccountType::USER,
    ]);
});

/**
 * Give an account a reachable number the way the platform actually does it: a
 * confirmed SMS multi-factor method. Notification never invents a phone field of its
 * own, so this is the only route to one.
 */
function givePhoneNumber(User $user, string $number): MfaMethod
{
    $method = new MfaMethod([
        'user_id' => $user->id,
        'type' => MfaType::SMS_OTP->value,
    ]);

    $method->type = MfaType::SMS_OTP;
    $method->setDestination($number);
    $method->confirmed_at = now();
    $method->save();

    return $method;
}
// ── Templates and translation ─────────────────────────────────────────────────

test('every registered notification type ships with a template', function (): void {
    foreach (NotificationType::cases() as $type) {
        $template = NotificationTemplate::query()->where('type', $type->value)->first();

        expect($template)->not->toBeNull()
            ->and($template->is_active)->toBeTrue();
    }
});

test('templates are stored as relational translations, one row per locale', function (): void {
    $template = NotificationTemplate::query()->where('type', NotificationType::SECURITY_ALERT->value)->firstOrFail();

    expect($template->translatedLocales())->toContain('en')
        ->and($template->translatedLocales())->toContain('ar');

    // A locale is a row, per ADR 0015 — not a JSON key on the parent.
    $rows = DB::table('notification_template_translations')
        ->where('notification_template_id', $template->id)
        ->count();

    expect($rows)->toBe(2);
});

test('a template renders in the requested locale', function (): void {
    $english = $this->renderer->render(NotificationType::SECURITY_ALERT, 'en', ['name' => 'Sam', 'event' => 'a new sign-in']);
    $arabic = $this->renderer->render(NotificationType::SECURITY_ALERT, 'ar', ['name' => 'Sam', 'event' => 'a new sign-in']);

    expect($english->subject)->toBe('Security alert on your account')
        ->and($english->body)->toContain('Sam')
        ->and($english->body)->toContain('a new sign-in')
        ->and($arabic->subject)->toBe('تنبيه أمني على حسابك')
        ->and($arabic->body)->toContain('Sam');
});

test('an untranslated locale falls back to the platform default rather than to nothing', function (): void {
    $rendered = $this->renderer->render(NotificationType::SECURITY_ALERT, 'fr', ['name' => 'Sam', 'event' => 'x']);

    // French has no translation; English is the default language.
    expect($rendered->subject)->toBe('Security alert on your account');
});

test('placeholders are substituted literally and never interpreted', function (): void {
    $rendered = $this->renderer->render(NotificationType::SECURITY_ALERT, 'en', [
        'name' => ':event',
        'event' => '<script>alert(1)</script>',
    ]);

    // :event supplied as a value must not itself be substituted again.
    expect($rendered->body)->toContain(':event')
        ->and($rendered->body)->toContain('<script>alert(1)</script>');
});

test('an unknown placeholder is left visible rather than blanked', function (): void {
    $rendered = $this->renderer->render(NotificationType::SECURITY_ALERT, 'en', ['name' => 'Sam']);

    // A missing value should be obvious in the delivered text, not a silent gap.
    expect($rendered->body)->toContain(':event');
});

test('dispatching without an active template raises rather than sending an empty message', function (): void {
    NotificationTemplate::query()->where('type', NotificationType::ACCOUNT_UPDATED->value)->delete();

    expect(fn () => $this->renderer->render(NotificationType::ACCOUNT_UPDATED, 'en'))
        ->toThrow(MissingTemplateException::class);
});

test('an inactive template is treated as missing', function (): void {
    NotificationTemplate::query()
        ->where('type', NotificationType::ACCOUNT_UPDATED->value)
        ->update(['is_active' => false]);

    expect(fn () => $this->renderer->render(NotificationType::ACCOUNT_UPDATED, 'en'))
        ->toThrow(MissingTemplateException::class);
});

test('the translations trait refuses an attribute the entity does not translate', function (): void {
    $template = NotificationTemplate::query()->first();

    expect(fn () => $template->translate('nonexistent'))
        ->toThrow(InvalidArgumentException::class);
});

// ── Preferences ───────────────────────────────────────────────────────────────

test('a recipient with no stored preference gets the notification defaults', function (): void {
    $channels = $this->preferences->channelsFor($this->user, NotificationType::ACCOUNT_UPDATED);

    expect(array_map(fn ($c) => $c->value, $channels))
        ->toBe([NotificationChannel::DATABASE->value, NotificationChannel::MAIL->value]);

    // No rows are written merely by asking.
    expect(NotificationPreference::query()->count())->toBe(0);
});

test('a stored preference overrides the default', function (): void {
    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::MAIL, false);
    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::SMS, true);

    $channels = array_map(fn ($c) => $c->value, $this->preferences->channelsFor($this->user, NotificationType::ACCOUNT_UPDATED));

    expect($channels)->toContain(NotificationChannel::SMS->value)
        ->and($channels)->not->toContain(NotificationChannel::MAIL->value);
});

test('the in-app record cannot be silenced', function (): void {
    expect(fn () => $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::DATABASE, false))
        ->toThrow(InvalidArgumentException::class);

    expect($this->preferences->channelsFor($this->user, NotificationType::ACCOUNT_UPDATED))
        ->toContain(NotificationChannel::DATABASE);
});

test('a security alert cannot be silenced on any channel', function (): void {
    foreach (NotificationChannel::cases() as $channel) {
        expect(fn () => $this->preferences->set($this->user, NotificationType::SECURITY_ALERT, $channel, false))
            ->toThrow(InvalidArgumentException::class);
    }

    $channels = $this->preferences->channelsFor($this->user, NotificationType::SECURITY_ALERT);

    expect($channels)->toContain(NotificationChannel::DATABASE)
        ->and($channels)->toContain(NotificationChannel::MAIL);
});

test('a preference row written before the rules tightened cannot suppress a mandatory notification', function (): void {
    // Force the row the API refuses to create.
    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'type' => NotificationType::SECURITY_ALERT->value,
        'channel' => NotificationChannel::MAIL->value,
        'enabled' => false,
    ]);

    // The rule is evaluated, not merely enforced at the point of writing.
    expect($this->preferences->channelsFor($this->user, NotificationType::SECURITY_ALERT))
        ->toContain(NotificationChannel::MAIL);
});

test('describe reports every combination with whether it can be silenced', function (): void {
    $described = $this->preferences->describe($this->user);

    expect($described)->toHaveCount(count(NotificationType::cases()) * count(NotificationChannel::cases()));

    $security = collect($described)->firstWhere(
        fn (array $row): bool => $row['type'] === NotificationType::SECURITY_ALERT->value
            && $row['channel'] === NotificationChannel::MAIL->value
    );

    expect($security['silenceable'])->toBeFalse()
        ->and($security['enabled'])->toBeTrue();
});

// ── Dispatch ──────────────────────────────────────────────────────────────────

test('notifications are queued on the notifications queue rather than run inline', function (): void {
    Queue::fake();

    $this->notifier->send($this->user, NotificationType::ACCOUNT_UPDATED, ['name' => 'Sam', 'event' => 'email changed']);

    Queue::assertPushed(SendQueuedNotifications::class, function ($job): bool {
        // A burst of notifications must not delay user-facing work on the default queue.
        return $job->notification->queue === 'notifications';
    });
});

test('a dispatched notification reaches only the channels the recipient allows', function (): void {
    NotificationFacade::fake();

    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::MAIL, false);

    $this->notifier->send($this->user, NotificationType::ACCOUNT_UPDATED, ['name' => 'Sam', 'event' => 'x']);

    NotificationFacade::assertSentTo($this->user, TemplatedNotification::class, function ($notification, array $channels): bool {
        return in_array('database', $channels, true) && ! in_array('mail', $channels, true);
    });
});

test('the in-app record stores the rendered text', function (): void {
    $this->notifier->send($this->user, NotificationType::ACCOUNT_UPDATED, ['name' => 'Sam', 'event' => 'email changed']);

    $row = DB::table('notifications')->where('notifiable_id', $this->user->id)->first();

    expect($row)->not->toBeNull();

    $data = json_decode((string) $row->data, true);

    expect($data['subject'])->toBe('Your account was updated')
        ->and($data['body'])->toContain('Sam')
        ->and($data['body'])->toContain('email changed');
});

test('each recipient is rendered in their own language', function (): void {
    $arabicUser = makeAccount(['email' => 'arabic@example.com', 'account_type' => AccountType::USER]);
    $arabicUser->forceFill(['preferred_locale' => 'ar'])->save();

    $this->notifier->sendMany([$this->user, $arabicUser], NotificationType::SECURITY_ALERT, [
        'name' => 'Sam', 'event' => 'a new sign-in',
    ]);

    $english = json_decode((string) DB::table('notifications')->where('notifiable_id', $this->user->id)->value('data'), true);
    $arabic = json_decode((string) DB::table('notifications')->where('notifiable_id', $arabicUser->id)->value('data'), true);

    expect($english['subject'])->toBe('Security alert on your account')
        ->and($arabic['subject'])->toBe('تنبيه أمني على حسابك');
});

// ── The SMS channel ───────────────────────────────────────────────────────────

test('SMS goes out through the Integration module for a recipient with a confirmed number', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_notification'], 201)]);

    // Twilio must be the default, or the chain succeeds on the log driver first and
    // no HTTP request is ever made.
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->setCredentials(['account_sid' => 'AC_t', 'auth_token' => 'tok']);
    $twilio->forceFill(['settings' => ['from' => '+15550000000'], 'is_active' => true])->save();
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false, 'is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);

    // A confirmed SMS MFA method is what gives the account a reachable number.
    givePhoneNumber($this->user, '+15551234567');

    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::SMS, true);

    $this->notifier->send($this->user, NotificationType::ACCOUNT_UPDATED, ['name' => 'Sam', 'event' => 'x']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'Messages.json')
        && str_contains((string) $request['Body'], 'Sam'));
});

test('a recipient with no reachable number is skipped rather than failing the notification', function (): void {
    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::SMS, true);

    // No confirmed SMS method exists, so there is nowhere to send.
    $this->notifier->send($this->user, NotificationType::ACCOUNT_UPDATED, ['name' => 'Sam', 'event' => 'x']);

    // The in-app record still landed: one unavailable route must not fail the others.
    expect(DB::table('notifications')->where('notifiable_id', $this->user->id)->count())->toBe(1);
});

// ── The preferences API ───────────────────────────────────────────────────────

test('a signed-in user reads their own preference matrix', function (): void {
    $regular = regularWithToken($this, 'prefs@example.com');

    $data = $this->withToken($regular['token'])
        ->getJson('/api/v1/notifications/preferences')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(count(NotificationType::cases()) * count(NotificationChannel::cases()));
});

test('a user can silence an optional notification through the API', function (): void {
    $regular = regularWithToken($this, 'silencer@example.com');

    $this->withToken($regular['token'])
        ->putJson('/api/v1/notifications/preferences', [
            'preferences' => [
                ['type' => NotificationType::ACCOUNT_UPDATED->value, 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertOk();

    $channels = $this->preferences->channelsFor($regular['user'], NotificationType::ACCOUNT_UPDATED);

    expect($channels)->not->toContain(NotificationChannel::MAIL);
});

test('the API refuses to silence a mandatory notification rather than ignoring it', function (): void {
    $regular = regularWithToken($this, 'refused@example.com');

    $this->withToken($regular['token'])
        ->putJson('/api/v1/notifications/preferences', [
            'preferences' => [
                ['type' => NotificationType::SECURITY_ALERT->value, 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PREFERENCE_NOT_SILENCEABLE');

    expect(NotificationPreference::query()->count())->toBe(0);
});

test('an unknown notification type or channel is rejected', function (): void {
    $regular = regularWithToken($this, 'unknown@example.com');

    $this->withToken($regular['token'])
        ->putJson('/api/v1/notifications/preferences', [
            'preferences' => [['type' => 'not.a.type', 'channel' => 'mail', 'enabled' => false]],
        ])
        ->assertStatus(422);

    $this->withToken($regular['token'])
        ->putJson('/api/v1/notifications/preferences', [
            'preferences' => [['type' => NotificationType::ACCOUNT_UPDATED->value, 'channel' => 'carrier-pigeon', 'enabled' => false]],
        ])
        ->assertStatus(422);
});

test('preferences require authentication', function (): void {
    $this->getJson('/api/v1/notifications/preferences')->assertStatus(401);
    $this->putJson('/api/v1/notifications/preferences', ['preferences' => []])->assertStatus(401);
});

// ── The template admin API ────────────────────────────────────────────────────

test('an admin with notifications.view can read templates', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'template-admin@example.com');

    $data = $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/notifications/templates')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(count(NotificationType::cases()));
});

test('an admin without notifications.view is refused', function (): void {
    $admin = adminWithRoles($this, ['support'], 'weak-template@example.com');

    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/notifications/templates')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('a regular user cannot reach the template admin API', function (): void {
    $regular = regularWithToken($this, 'not-admin@example.com');

    $this->withToken($regular['token'])
        ->getJson('/api/v1/admin/notifications/templates')
        ->assertStatus(403);
});

test('editing one language does not delete the others', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'editor-admin@example.com');
    $template = NotificationTemplate::query()->where('type', NotificationType::SECURITY_ALERT->value)->firstOrFail();

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/notifications/templates/'.$template->id, [
            'translations' => [
                ['locale' => 'en', 'subject' => 'Rewritten subject', 'body' => 'Rewritten body for :name.'],
            ],
        ])
        ->assertOk();

    $refreshed = $template->refresh()->loadMissing('translations');

    expect($refreshed->translate('subject', 'en'))->toBe('Rewritten subject')
        // The Arabic translation was not part of the request and must survive.
        ->and($refreshed->translate('subject', 'ar'))->toBe('تنبيه أمني على حسابك');
});

test('a template cannot be translated into a language the platform has not configured', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'bad-locale@example.com');
    $template = NotificationTemplate::query()->first();

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/notifications/templates/'.$template->id, [
            'translations' => [['locale' => 'zz', 'subject' => 'x', 'body' => 'y']],
        ])
        ->assertStatus(422);
});

test('the notification type of a template cannot be repointed through the API', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'repoint@example.com');
    $template = NotificationTemplate::query()->where('type', NotificationType::SECURITY_ALERT->value)->firstOrFail();

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/notifications/templates/'.$template->id, [
            'is_active' => true,
            'type' => NotificationType::ADMIN_ANNOUNCEMENT->value,
        ])
        ->assertOk();

    expect($template->refresh()->type)->toBe(NotificationType::SECURITY_ALERT);
});

// ── Seeding and database invariants ───────────────────────────────────────────

test('re-running the seeder never overwrites edited wording', function (): void {
    $template = NotificationTemplate::query()->where('type', NotificationType::SECURITY_ALERT->value)->firstOrFail();
    $template->setTranslation('en', ['subject' => 'Operator wording', 'body' => 'Operator body.']);

    $this->seed(NotificationTemplateSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);

    expect($template->refresh()->loadMissing('translations')->translate('subject', 'en'))->toBe('Operator wording')
        ->and(NotificationTemplate::query()->count())->toBe(count(NotificationType::cases()));
});

test('the database rejects an unknown notification type or channel', function (): void {
    foreach ([
        ['notification_templates', ['type' => 'not.a.type', 'is_active' => true]],
        ['notification_preferences', ['type' => 'not.a.type', 'channel' => 'mail', 'enabled' => true, 'user_id' => $this->user->id]],
        ['notification_preferences', ['type' => NotificationType::ACCOUNT_UPDATED->value, 'channel' => 'pigeon', 'enabled' => true, 'user_id' => $this->user->id]],
    ] as [$table, $attributes]) {
        $rejected = false;

        try {
            DB::transaction(fn () => DB::table($table)->insert(array_merge([
                'id' => (string) Str::ulid(),
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes)));
        } catch (QueryException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue("{$table} should have rejected ".json_encode($attributes));
    }
});

test('a recipient has at most one preference per notification and channel', function (): void {
    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::MAIL, false);
    $this->preferences->set($this->user, NotificationType::ACCOUNT_UPDATED, NotificationChannel::MAIL, true);

    expect(NotificationPreference::query()
        ->where('user_id', $this->user->id)
        ->where('type', NotificationType::ACCOUNT_UPDATED->value)
        ->where('channel', NotificationChannel::MAIL->value)
        ->count())->toBe(1);
});
