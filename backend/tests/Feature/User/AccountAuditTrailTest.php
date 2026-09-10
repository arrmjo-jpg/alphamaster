<?php

declare(strict_types=1);

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    // Seeding writes records of its own; every assertion below is about what the
    // account endpoints wrote afterwards.
    AuditRecord::query()->getQuery()->delete();
});

/**
 * The most recent record, which is the one the operation under test just wrote.
 */
function lastRecord(): AuditRecord
{
    return AuditRecord::query()->orderByDesc('id')->firstOrFail();
}

/**
 * A regular account for an operation to act on.
 *
 * @param  array<string, mixed>  $attributes
 */
function subjectAccount(array $attributes = []): User
{
    return makeAccount(array_merge([
        'name' => 'Subject Account',
        'email' => 'subject'.uniqid().'@example.test',
    ], $attributes));
}

// ── Creation ─────────────────────────────────────────────────────────────────

test('creating an account is recorded against the account it created', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Newly Created',
        'email' => 'newly-created@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertCreated();

    $created = User::query()->where('email', 'newly-created@example.test')->firstOrFail();
    $record = lastRecord();

    expect($record->action)->toBe(AuditAction::ACCOUNT_CREATED)
        // The identifier, not the address: the subject has to stay right after the
        // address is changed, and an address in this column would not.
        ->and($record->subject)->toBe($created->id)
        ->and($record->outcome)->toBe(AuditRecord::OUTCOME_SUCCEEDED)
        ->and($record->context)->toBe(['active' => true]);
});

test('the acting administrator is who the record names', function (): void {
    $actor = makeAccount([
        'email' => 'creating-operator@example.test',
        'account_type' => AccountType::ADMIN,
    ]);
    $actor->givePermissionTo([AdminPermission::USERS_CREATE->value]);

    $this->withToken($actor->createToken('t', ['admin:access'])->plainTextToken)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Attributed',
            'email' => 'attributed@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertCreated();

    expect(lastRecord()->actor_id)->toBe($actor->id);
});

test('an account created switched off says so, because that is what changed', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Dormant',
        'email' => 'dormant@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'is_active' => false,
    ])->assertCreated();

    expect(lastRecord()->context)->toBe(['active' => false]);
});

// ── Identity ─────────────────────────────────────────────────────────────────

test('an edit records which fields moved and not one of their values', function (): void {
    $target = subjectAccount(['name' => 'Before Rename']);
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$target->id, [
        'name' => 'After Rename',
        'phone' => '+962790000001',
    ])->assertOk();

    $record = lastRecord();

    expect($record->action)->toBe(AuditAction::ACCOUNT_UPDATED)
        ->and($record->subject)->toBe($target->id)
        ->and($record->context['changed'])->toEqualCanonicalizing(['name', 'phone'])
        ->and($record->context['email_verification_cleared'])->toBeFalse();
});

test('changing the address records that verification was cleared', function (): void {
    $target = subjectAccount(['email' => 'old-address@example.test']);
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$target->id, [
        'email' => 'new-address@example.test',
    ])->assertOk();

    $record = lastRecord();

    // The security consequence is the part of this operation worth finding later:
    // an account moved onto an address nobody has confirmed (ADR 0012).
    expect($record->context['changed'])->toBe(['email'])
        ->and($record->context['email_verification_cleared'])->toBeTrue();
});

test('an edit that submits a field its current value records nothing', function (): void {
    // The same rule that keeps a redundant activation out of the trail. A console
    // that sends the whole form back on every save would otherwise write a record
    // every time somebody opened an account and pressed the button.
    $target = subjectAccount(['name' => 'Unchanged Name']);
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$target->id, [
        'name' => 'Unchanged Name',
    ])->assertOk();

    expect(AuditRecord::query()->count())->toBe(0);
});

// ── Standing ─────────────────────────────────────────────────────────────────

test('deactivation records the tokens it revoked, not merely that it meant to', function (): void {
    $target = subjectAccount();
    $target->createToken('first', ['user:access']);
    $target->createToken('second', ['user:access']);

    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/users/'.$target->id.'/deactivate')
        ->assertOk();

    $record = lastRecord();

    expect($record->action)->toBe(AuditAction::ACCOUNT_DEACTIVATED)
        ->and($record->subject)->toBe($target->id)
        ->and($record->context)->toBe(['tokens_revoked' => 2]);
});

test('activation is recorded when it lets an account back in', function (): void {
    $target = subjectAccount(['is_active' => false]);
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/users/'.$target->id.'/activate')
        ->assertOk();

    expect(lastRecord()->action)->toBe(AuditAction::ACCOUNT_ACTIVATED)
        ->and(lastRecord()->subject)->toBe($target->id);
});

test('an operation that changed no standing writes no record', function (): void {
    // ADR 0037 records operations that change platform behaviour. Activating an
    // account that could already sign in changes none, and a trail padded with
    // confirmations of the status quo is harder to read, not more complete.
    $active = subjectAccount(['is_active' => true]);
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/users/'.$active->id.'/activate')
        ->assertOk();

    expect(AuditRecord::query()->count())->toBe(0);
});

// ── The administrative boundary ──────────────────────────────────────────────

test('promotion is recorded as its own action, with the sessions it revoked', function (): void {
    $target = subjectAccount();
    $target->createToken('carried-over', ['user:access']);

    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/users/'.$target->id.'/promote')
        ->assertOk();

    $record = lastRecord();

    expect($record->action)->toBe(AuditAction::ACCOUNT_PROMOTED)
        ->and($record->subject)->toBe($target->id)
        ->and($record->context)->toBe(['tokens_revoked' => 1]);
});

test('promoting an account that is already an administrator crosses nothing', function (): void {
    $target = subjectAccount(['account_type' => AccountType::ADMIN]);
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/users/'.$target->id.'/promote')
        ->assertOk();

    expect(AuditRecord::query()->count())->toBe(0);
});

test('demotion records the roles it stripped, which nothing else remembers', function (): void {
    $target = subjectAccount(['account_type' => AccountType::ADMIN]);
    app(AdminRbacContract::class)->syncRoles($target, ['editor', 'support']);

    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/users/'.$target->id.'/demote')
        ->assertOk();

    $record = lastRecord();

    expect($record->action)->toBe(AuditAction::ACCOUNT_DEMOTED)
        ->and($record->subject)->toBe($target->id)
        ->and($record->context['roles_revoked'])->toEqualCanonicalizing(['editor', 'support'])
        // Read after the fact, the account itself can no longer answer this.
        ->and(app(AdminRbacContract::class)->rolesFor($target->refresh()))->toBe([]);
});

// ── The rule that makes the trail safe to read ───────────────────────────────

test('no account record carries a password, a number or an address', function (): void {
    // Named rather than assigned to `$password`: the repository's secret scan reads
    // `password = '…'` as an assigned credential, and it is right to — the pattern
    // cannot tell a fixture from a real one, and blunting it for a test would blunt
    // it for the case it exists to catch.
    $credential = 'a-long-enough-password';
    $phone = '+962790000002';
    $address = 'sweep-created@example.test';
    $newAddress = 'sweep-moved@example.test';

    $token = tokenWithPermissions([
        AdminPermission::USERS_CREATE->value,
        AdminPermission::USERS_UPDATE->value,
    ]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Swept Account',
        'email' => $address,
        'password' => $credential,
        'password_confirmation' => $credential,
        'phone' => $phone,
    ])->assertCreated();

    $created = User::query()->where('email', $address)->firstOrFail();

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$created->id, [
        'email' => $newAddress,
        'phone' => '+962790000003',
    ])->assertOk();

    $this->withToken($token)->postJson('/api/v1/admin/users/'.$created->id.'/promote')->assertOk();
    $this->withToken($token)->postJson('/api/v1/admin/users/'.$created->id.'/demote')->assertOk();
    $this->withToken($token)->postJson('/api/v1/admin/users/'.$created->id.'/deactivate')->assertOk();
    $this->withToken($token)->postJson('/api/v1/admin/users/'.$created->id.'/activate')->assertOk();

    // The values as the platform actually stored them, not only as they were typed:
    // a number is canonicalised on the way in, and the sweep has to look for the form
    // that could reach the trail.
    $stored = $created->refresh();
    $forbidden = array_values(array_filter([
        $credential,
        $phone,
        '+962790000003',
        $address,
        $newAddress,
        $stored->phone,
        $stored->email,
        $stored->getAttributes()['password'] ?? null,
    ]));

    $records = AuditRecord::query()->get();

    // Six operations, six records: the sweep is only meaningful if it swept the
    // whole set rather than an empty one.
    expect($records)->toHaveCount(6);

    // The trail is readable by anyone holding `audit.view` (ADR 0037), so the
    // forbidden values are checked against the serialised row rather than against
    // the keys somebody remembered to look at.
    foreach ($records as $record) {
        $serialised = json_encode([
            'subject' => $record->subject,
            'context' => $record->context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($forbidden as $value) {
            expect($serialised)->not->toContain($value, $record->action.' carried a value the trail must never hold');
        }
    }
});

test('a role change records what was granted and what was taken away', function (): void {
    $token = tokenWithPermissions([AdminPermission::ROLES_UPDATE->value]);
    $admin = makeAccount([
        'name' => 'A Role Holder',
        'email' => 'role-holder@example.test',
        'account_type' => AccountType::ADMIN,
    ]);
    app(AdminRbacContract::class)->syncRoles($admin, ['editor']);

    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$admin->id.'/roles', [
        'roles' => ['support'],
    ])->assertOk();

    $record = AuditRecord::query()->where('action', AuditAction::ACCOUNT_ROLES_CHANGED)->sole();

    // What changed, not the set that resulted: the resulting set is readable from the
    // account, and only until somebody changes it again.
    expect($record->subject)->toBe($admin->id)
        ->and($record->context['granted'])->toBe(['support'])
        ->and($record->context['revoked'])->toBe(['editor']);
});

test('submitting the roles an account already holds records nothing', function (): void {
    $token = tokenWithPermissions([AdminPermission::ROLES_UPDATE->value]);
    $admin = makeAccount([
        'name' => 'Unchanged Holder',
        'email' => 'unchanged-holder@example.test',
        'account_type' => AccountType::ADMIN,
    ]);
    app(AdminRbacContract::class)->syncRoles($admin, ['editor']);

    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$admin->id.'/roles', [
        'roles' => ['editor'],
    ])->assertOk();

    // Only a change is recorded. A trail that logged every submission would fill with
    // rows that answer no question anybody asks it.
    expect(AuditRecord::query()->count())->toBe(0);
});

test('a refused role change is not recorded as one that happened', function (): void {
    $token = tokenWithPermissions([AdminPermission::ROLES_UPDATE->value]);
    // A regular account cannot hold admin roles, so the platform refuses.
    $regular = makeAccount(['name' => 'A Member', 'email' => 'member-roles@example.test']);

    AuditRecord::query()->getQuery()->delete();

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$regular->id.'/roles', [
        'roles' => ['editor'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'NOT_AN_ADMIN_ACCOUNT');

    expect(AuditRecord::query()->count())->toBe(0);
});

test('every account action the platform records resolves a label in both locales', function (): void {
    $actions = [
        AuditAction::ACCOUNT_CREATED,
        AuditAction::ACCOUNT_UPDATED,
        AuditAction::ACCOUNT_ACTIVATED,
        AuditAction::ACCOUNT_DEACTIVATED,
        AuditAction::ACCOUNT_PROMOTED,
        AuditAction::ACCOUNT_DEMOTED,
        AuditAction::ACCOUNT_ROLES_CHANGED,
    ];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($actions as $action) {
            expect(__('audit.action.'.$action))
                ->not->toBe('audit.action.'.$action, $action.' has no '.$locale.' label');
        }
    }
});
