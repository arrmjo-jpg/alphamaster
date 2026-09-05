<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Resources\IntegrationProviderResource;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Notification\Resources\NotificationTemplateResource;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Resources\UserResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * One resource of each kind this commit labels, with its labelled field.
 *
 * @return array<string, array{0: object, 1: string, 2: string}>
 */
function labelledResources(): array
{
    $user = makeAccount(['email' => 'labelled@example.test', 'account_type' => AccountType::ADMIN]);

    $provider = new IntegrationProvider([
        'driver' => 'log',
        'label' => 'Log provider',
        'is_active' => true,
    ]);
    $provider->capability = IntegrationCapability::SMS;
    $provider->priority = 0;
    $provider->save();

    $template = NotificationTemplate::query()->where('type', NotificationType::SECURITY_ALERT)->first()
        ?? tap(new NotificationTemplate(['is_active' => true]), function (NotificationTemplate $t): void {
            $t->type = NotificationType::SECURITY_ALERT;
            $t->save();
        });
    $template->loadMissing('translations');

    return [
        'user' => [new UserResource($user, [], []), 'account_type', 'admin'],
        'provider' => [new IntegrationProviderResource($provider), 'capability', 'sms'],
        'template' => [new NotificationTemplateResource($template), 'type', 'security.alert'],
    ];
}

// ── The technical values are untouched ───────────────────────────────────────

test('every raw enum value is unchanged in both locales', function (): void {
    $resources = labelledResources();

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($resources as $name => [$resource, $field, $value]) {
            $payload = $resource->toArray(request());

            expect($payload[$field])->toBe($value, $name.'.'.$field.' moved in '.$locale);
        }
    }
});

test('a value is never replaced by its label', function (): void {
    app()->setLocale('ar');

    foreach (labelledResources() as $name => [$resource, $field, $value]) {
        $payload = $resource->toArray(request());

        expect($payload[$field.'_label'])->not->toBe($payload[$field], $name);
    }
});

// ── The labels ───────────────────────────────────────────────────────────────

test('each label reads in English', function (): void {
    app()->setLocale('en');

    $resources = labelledResources();

    expect($resources['user'][0]->toArray(request())['account_type_label'])->toBe('Administrator')
        ->and($resources['provider'][0]->toArray(request())['capability_label'])->toBe('SMS')
        ->and($resources['template'][0]->toArray(request())['type_label'])->toBe('Security Alert');
});

test('each label reads in Arabic', function (): void {
    app()->setLocale('ar');

    $resources = labelledResources();

    expect($resources['user'][0]->toArray(request())['account_type_label'])->toBe('مدير')
        ->and($resources['provider'][0]->toArray(request())['capability_label'])->toBe('الرسائل النصية')
        ->and($resources['template'][0]->toArray(request())['type_label'])->toBe('تنبيه أمني');
});

test('switching locale changes the label and never the value', function (): void {
    // ADR 0031 rule 2: the same record reads differently to two callers and
    // identically to the code.
    $resources = labelledResources();

    foreach ($resources as $name => [$resource, $field, $value]) {
        app()->setLocale('en');
        $english = $resource->toArray(request());

        app()->setLocale('ar');
        $arabic = $resource->toArray(request());

        expect($arabic[$field])->toBe($english[$field], $name.' value moved')
            ->and($arabic[$field])->toBe($value)
            ->and($arabic[$field.'_label'])->not->toBe($english[$field.'_label'], $name.' label did not follow the locale');
    }
});

test('every case of every enum these resources expose resolves in both locales', function (): void {
    $enums = [AccountType::class, IntegrationCapability::class, NotificationType::class];
    $checked = 0;

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                expect($case->label())->not->toBe($case->value, $enum.'::'.$case->value.' in '.$locale);
                $checked++;
            }
        }
    }

    expect($checked)->toBe(12);
});

// ── The shapes ───────────────────────────────────────────────────────────────

test('each resource exposes exactly its own fields, with the label beside the value', function (): void {
    app()->setLocale('en');

    $resources = labelledResources();

    expect(array_keys($resources['user'][0]->toArray(request())))->toBe([
        'id', 'name', 'email', 'account_type', 'account_type_label', 'is_active', 'roles', 'permissions',
    ]);

    expect(array_keys($resources['provider'][0]->toArray(request())))->toBe([
        'id', 'capability', 'capability_label', 'driver', 'label', 'settings',
        'has_credentials', 'is_active', 'is_default', 'priority', 'updated_at',
    ]);

    expect(array_keys($resources['template'][0]->toArray(request())))->toBe([
        'id', 'type', 'type_label', 'is_active', 'translations', 'updated_at',
    ]);
});

test('the provider keeps its own label field distinct from the capability label', function (): void {
    // The provider already had a `label` of its own. The capability's label is a
    // separate field and neither overwrites the other.
    app()->setLocale('ar');

    $payload = labelledResources()['provider'][0]->toArray(request());

    expect($payload['label'])->toBe('Log provider')
        ->and($payload['capability_label'])->toBe('الرسائل النصية')
        ->and($payload['label'])->not->toBe($payload['capability_label']);
});

test('this commit adds exactly one field to each resource', function (): void {
    app()->setLocale('en');

    foreach (labelledResources() as $name => [$resource, $field, $value]) {
        $labels = array_values(array_filter(
            array_keys($resource->toArray(request())),
            static fn (string $key): bool => str_ends_with($key, '_label') && $key !== 'label'
        ));

        expect($labels)->toBe([$field.'_label'], $name.' gained an unexpected field');
    }
});

// ── End to end ───────────────────────────────────────────────────────────────

test('the labels reach a real response and follow X-Locale', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'label-admin@example.test')['token'];

    foreach (['en' => 'Administrator', 'ar' => 'مدير'] as $locale => $expected) {
        resetClient($this);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => $locale])
            ->getJson('/api/v1/admin/users');

        $response->assertOk();

        $rows = collect($response->json('data'));

        expect($rows)->not->toBeEmpty()
            ->and($rows->pluck('account_type')->unique()->all())->toBe(['admin'])
            ->and($rows->pluck('account_type_label')->unique()->all())->toBe([$expected]);
    }
});

test('a template response carries its type label over HTTP', function (): void {
    $this->seed(NotificationTemplateSeeder::class);

    $token = adminWithRoles($this, ['super_admin'], 'template-label@example.test')['token'];

    resetClient($this);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => 'ar'])
        ->getJson('/api/v1/admin/notifications/templates');

    $response->assertOk();

    $first = $response->json('data.0');

    expect($first)->toHaveKey('type')
        ->and($first)->toHaveKey('type_label')
        ->and($first['type_label'])->not->toBe($first['type']);
});
