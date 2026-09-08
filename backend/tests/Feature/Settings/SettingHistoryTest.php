<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);

    // Provisioning writes no revisions, but start from a clean slate regardless so a
    // count means what it says.
    SettingRevision::query()->getQuery()->delete();
});

/** Read a group's history through the API. */
function history(mixed $test, string $group, string $query = '', ?string $token = null): mixed
{
    $token ??= adminToken(roles: ['administrator']);

    return $test->withToken($token)->getJson('/api/v1/admin/settings/'.$group.'/history'.$query);
}

// ── The store ────────────────────────────────────────────────────────────────

test('setting_revisions has the shape ADR 0040 describes', function (): void {
    expect(Schema::hasTable('setting_revisions'))->toBeTrue()
        ->and(Schema::hasColumns('setting_revisions', ['id', 'setting_id', 'version', 'locale', 'value', 'actor_id', 'created_at']))
        ->toBeTrue()
        // A revision records what already happened, so there is nothing to update.
        ->and(Schema::hasColumn('setting_revisions', 'updated_at'))->toBeFalse();
});

test('a revision cannot be updated through the application', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    $revision = SettingRevision::query()->firstOrFail();

    expect(fn () => $revision->update(['value' => 'tampered']))
        ->toThrow(RuntimeException::class, 'cannot be updated');
});

test('revisions go with their setting', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    $setting = Setting::query()->where('key', 'date_format')->firstOrFail();

    expect(SettingRevision::query()->forSetting($setting->id)->count())->toBe(1);

    $setting->delete();

    expect(SettingRevision::query()->forSetting($setting->id)->count())->toBe(0);
});

// ── What is captured ─────────────────────────────────────────────────────────

test('a change records the value it superseded, not the new one', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    $revision = SettingRevision::query()->firstOrFail();

    expect($revision->value)->toBe('Y-m-d')
        ->and($this->service->get('localization.date_format'))->toBe('d/m/Y');
});

test('the recorded version is the one the value belonged to', function (): void {
    // Captured before the counter advances, so a rollback can name a target
    // unambiguously.
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('localization', 'date_format', 'Y.m.d');

    $versions = SettingRevision::query()->orderBy('version')->pluck('version')->all();

    expect($versions)->toBe([0, 1]);
});

test('a write that changes nothing records nothing', function (): void {
    // History answers what a value used to be; entries where the answer is "the same"
    // make that harder to read, not easier.
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('localization', 'date_format', 'd/m/Y');

    expect(SettingRevision::query()->count())->toBe(1);
});

test('clearing a setting records what it held', function (): void {
    $this->service->set('localization', 'date_format', null);

    expect(SettingRevision::query()->firstOrFail()->value)->toBe('Y-m-d');
});

test('the actor is recorded, and null when nobody is acting', function (): void {
    $admin = makeAccount(['email' => 'historian@example.test']);
    $this->actingAs($admin);

    $this->service->set('localization', 'date_format', 'd/m/Y');

    expect(SettingRevision::query()->firstOrFail()->actor_id)->toBe($admin->id);

    app('auth')->forgetGuards();

    $this->service->set('localization', 'date_format', 'Y.m.d');

    expect(SettingRevision::query()->orderByDesc('id')->firstOrFail()->actor_id)->toBeNull();
});

// ── Secrets have no history at all ───────────────────────────────────────────

test('a secret write records no revision', function (): void {
    $this->service->set('security', 'api_secret_key', 'a-real-credential');

    expect(SettingRevision::query()->count())->toBe(0);
});

test('rotating a secret repeatedly still records nothing', function (): void {
    // Not one row per rotation, not a redacted row, not a null placeholder: nothing.
    foreach (['first', 'second', 'third', null] as $value) {
        $this->service->set('security', 'api_secret_key', $value);
    }

    expect(SettingRevision::query()->count())->toBe(0);
});

test('no secret material reaches the revision store in any form', function (): void {
    $plaintext = 'super-secret-credential-value';

    $this->service->set('security', 'api_secret_key', $plaintext);
    $this->service->set('localization', 'date_format', 'd/m/Y');

    $ciphertext = (string) Setting::query()
        ->where('group', 'security')->where('key', 'api_secret_key')->value('value');

    $everything = json_encode(SettingRevision::query()->get()->toArray());

    expect($everything)->not->toContain($plaintext)
        ->and($everything)->not->toContain($ciphertext)
        ->and($everything)->not->toContain(hash('sha256', $plaintext))
        ->and($everything)->not->toContain(md5($plaintext));
});

test('every secret in the catalogue is excluded, not just the one under test', function (): void {
    // The rule is structural, so it holds for a credential added to a future
    // catalogue without anybody remembering to exclude it.
    foreach (app(SettingRegistry::class)->all() as $definition) {
        if (! $definition->isSecret) {
            continue;
        }

        $this->service->set($definition->group, $definition->key, 'probe-'.$definition->key);
    }

    expect(SettingRevision::query()->count())->toBe(0);
});

// ── Localized history is per locale ──────────────────────────────────────────

test('a localized write records the value for its own locale', function (): void {
    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'اسم أول');
    $this->service->set('general', 'site_name', 'اسم ثانٍ');

    $arabic = SettingRevision::query()->where('locale', 'ar')->orderBy('version')->get();

    expect($arabic)->toHaveCount(2)
        ->and($arabic[1]->value)->toBe('اسم أول');
});

test('a locale that was never translated records nothing rather than the base value', function (): void {
    // The distinction that keeps a future rollback from inventing a translation that
    // never existed: an untranslated locale was holding null, not the fallback.
    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'اسم عربي');

    $first = SettingRevision::query()->where('locale', 'ar')->orderBy('version')->firstOrFail();

    expect($first->value)->toBeNull()
        ->and(Setting::query()->where('key', 'site_name')->value('value'))->toBe('AlphaMaster Enterprise');
});

test('writing one locale records nothing for another', function (): void {
    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'اسم عربي');

    expect(SettingRevision::query()->where('locale', 'en')->count())->toBe(0)
        ->and(SettingRevision::query()->where('locale', 'ar')->count())->toBe(1);
});

test('a non-localized setting records no locale', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    expect(SettingRevision::query()->firstOrFail()->locale)->toBeNull();
});

// ── The read contract ────────────────────────────────────────────────────────

test('history reads newest first with the value cast to its declared type', function (): void {
    $this->service->set('auth', 'password_min_length', 10);
    $this->service->set('auth', 'password_min_length', 12);

    $rows = history($this, 'auth')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        // Newest first: the most recent revision is the value just superseded.
        ->and($rows[0]['value'])->toBe(10)
        ->and($rows[1]['value'])->toBe(8)
        // ...and typed, not the raw stored string.
        ->and($rows[0]['value'])->toBeInt();
});

test('a history row carries exactly the declared fields', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    $rows = history($this, 'localization')->assertOk()->json('data');

    expect(array_keys($rows[0]))->toBe(['id', 'key', 'locale', 'version', 'value', 'actor_id', 'recorded_at']);
});

test('history can be narrowed to one setting', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('localization', 'timezone', 'Asia/Amman');

    $rows = history($this, 'localization', '?key=timezone')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['key'])->toBe('timezone');
});

test('an unknown group and an unknown key are 404, not an empty list', function (): void {
    // An empty list reads as "nothing ever changed", which is a different answer.
    history($this, 'no_such_group')->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_GROUP_NOT_FOUND');

    history($this, 'localization', '?key=no_such_key')->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_KEY_NOT_FOUND');
});

test('a group whose only changes were secret returns an empty history', function (): void {
    // Empty because there are no revisions, not because they were filtered out.
    $this->service->set('security', 'api_secret_key', 'a-credential');

    $response = history($this, 'security')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.count'))->toBe(0);
});

test('the limit is bounded rather than trusted', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    history($this, 'localization', '?limit=999999')->assertOk();
    history($this, 'localization', '?limit=0')->assertOk();
    history($this, 'localization', '?limit=-5')->assertOk();
    history($this, 'localization', '?limit=abc')->assertOk();
});

// ── Authorization ────────────────────────────────────────────────────────────

test('reading history requires settings.view', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    history($this, 'localization', '', adminToken(roles: ['support']))->assertStatus(403);

    resetClient($this);

    $this->getJson('/api/v1/admin/settings/localization/history')->assertStatus(401);
});

test('an admin who may read settings may read their history', function (): void {
    // Seeing what a value was is no more privileged than seeing what it is.
    $this->service->set('localization', 'date_format', 'd/m/Y');

    history($this, 'localization', '', adminToken(roles: ['editor']))->assertOk();
});

// ── Adversarial ──────────────────────────────────────────────────────────────

test('a group name cannot escape into another group history', function (): void {
    $this->service->set('security', 'api_secret_key', 'a-credential');
    $this->service->set('localization', 'date_format', 'd/m/Y');

    // The route pattern constrains the segment, so a traversal attempt does not
    // resolve at all rather than resolving to something else.
    $this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/settings/../security/history')
        ->assertStatus(404);
});

test('history for one group never leaks another group revisions', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('auth', 'password_min_length', 10);

    $rows = history($this, 'auth')->assertOk()->json('data');

    expect(array_column($rows, 'key'))->toBe(['password_min_length']);
});

test('the key filter cannot reach a setting in a different group', function (): void {
    $this->service->set('security', 'api_secret_key', 'a-credential');

    // api_secret_key exists, but not in this group.
    history($this, 'localization', '?key=api_secret_key')->assertStatus(404);
});

test('a rolled back transaction leaves no revision behind', function (): void {
    $before = SettingRevision::query()->count();

    try {
        DB::transaction(function (): void {
            $this->service->updateGroup('localization', ['date_format' => 'd/m/Y']);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(SettingRevision::query()->count())->toBe($before)
        ->and($this->service->get('localization.date_format'))->toBe('Y-m-d');
});

test('a failed batch records no revision for the settings it did reach', function (): void {
    // The write is atomic, so a batch that fails partway must leave no partial
    // history either — otherwise history shows a change that never happened.
    $before = SettingRevision::query()->count();

    $this->withToken(adminToken(roles: ['administrator']))
        ->withHeaders(['If-Match' => settingsVersion('localization')])
        ->putJson('/api/v1/admin/settings/localization', ['settings' => [
            'date_format' => 'd/m/Y',
            'timezone' => ['not' => 'a string'],
        ]])
        ->assertStatus(422);

    expect(SettingRevision::query()->count())->toBe($before)
        ->and($this->service->get('localization.date_format'))->toBe('Y-m-d');
});

test('the id a history row carries is the one rollback accepts', function (): void {
    // The point of publishing it. /rollback takes a revision id, and until this row
    // carried one there was no endpoint a client could learn it from — the two
    // operations were documented, permissioned, and impossible to compose.
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('localization', 'date_format', 'd.m.Y');

    $rows = history($this, 'localization')->assertOk()->json('data');

    // Asserted against the row's own value rather than a literal, because what is
    // being checked is that the id and the value belong to the same revision — a
    // hard-coded string would still pass if they did not.
    $target = $rows[0];

    resetClient($this);

    $this->withToken(adminToken(roles: ['super_admin']))
        ->withHeader('If-Match', '"'.settingsVersion('localization').'"')
        ->postJson('/api/v1/admin/settings/localization/rollback', ['revision_id' => $target['id']])
        ->assertOk();

    expect($this->service->get('localization.date_format'))->toBe($target['value']);
});
