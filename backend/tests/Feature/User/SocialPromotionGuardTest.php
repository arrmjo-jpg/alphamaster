<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Contracts\SocialIdentityRegistryContract;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Exceptions\PromotionRefusedException;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Promotion is refused while a social identity is linked (ADR 0050 §6), and only while
 * one is: identities unlinked in the past do not block it.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->token = adminToken(roles: ['super_admin']);

    // Every assertion below is about what promotion wrote.
    AuditRecord::query()->getQuery()->delete();
});

function attachSocialIdentity(User $user, string $provider = 'google', bool $linked = true): SocialIdentity
{
    return SocialIdentity::query()->create([
        'user_id' => $user->id,
        'provider' => $provider,
        'provider_subject' => 'subject-'.Str::lower(Str::random(16)),
        'linked_at' => now(),
        'unlinked_at' => $linked ? null : now(),
    ]);
}

/**
 * @return array<int, array{subject: string|null, outcome: string, context: array<string, mixed>|null}>
 */
function promotionRecords(string $action): array
{
    return DB::table('audit_records')
        ->where('action', $action)
        ->get()
        ->map(static fn (object $row): array => [
            'subject' => $row->subject,
            'outcome' => $row->outcome,
            'context' => is_string($row->context) ? json_decode($row->context, true) : null,
        ])
        ->all();
}

// ── Refused while linked ─────────────────────────────────────────────────────

test('an account with a linked social identity is refused promotion, and stays a user', function (): void {
    $user = makeAccount(['email' => 'linked@example.test']);
    attachSocialIdentity($user);

    $response = $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote');

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'PROMOTION_REFUSED_SOCIAL_IDENTITY')
        ->assertJsonPath('error.details.linked_identities', 1);

    expect($user->fresh()->account_type)->toBe(AccountType::USER);
});

test('every linked identity is counted in the refusal', function (): void {
    $user = makeAccount(['email' => 'two-linked@example.test']);
    attachSocialIdentity($user, 'google');
    attachSocialIdentity($user, 'example');

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')
        ->assertStatus(409)
        ->assertJsonPath('error.details.linked_identities', 2);
});

// ── Allowed otherwise ────────────────────────────────────────────────────────

test('an account whose identities are all unlinked is promoted', function (): void {
    $user = makeAccount(['email' => 'all-unlinked@example.test']);
    attachSocialIdentity($user, 'google', linked: false);
    attachSocialIdentity($user, 'example', linked: false);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertOk();

    expect($user->fresh()->account_type)->toBe(AccountType::ADMIN)
        ->and(SocialIdentity::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('an account with no identities is promoted exactly as before', function (): void {
    $user = makeAccount(['email' => 'no-identities@example.test']);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertOk();

    expect($user->fresh()->account_type)->toBe(AccountType::ADMIN)
        ->and(promotionRecords(AuditAction::ACCOUNT_PROMOTED))->toHaveCount(1)
        ->and(promotionRecords(AuditAction::ACCOUNT_PROMOTION_REFUSED))->toBe([]);
});

test('unlinking the last identity is what makes promotion possible', function (): void {
    $user = makeAccount(['email' => 'unlink-then-promote@example.test']);
    $identity = attachSocialIdentity($user);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertStatus(409);

    // The account holder's act, not the administrator's. The account has a password, so
    // its last identity may go.
    app(SocialIdentityRegistryContract::class)->unlink($user, $identity->id);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertOk();

    expect($user->fresh()->account_type)->toBe(AccountType::ADMIN);
});

// ── How the refusal is made ──────────────────────────────────────────────────

test('the decision is made on the account as it stands, not on the model a caller loaded', function (): void {
    $user = makeAccount(['email' => 'stale-model@example.test']);

    // Loaded before the identity existed, the way a caller holding an old copy would be.
    $stale = User::query()->findOrFail($user->id);

    attachSocialIdentity($user);

    expect(fn () => app(AccountTypeManagerContract::class)->promote($stale))
        ->toThrow(PromotionRefusedException::class);

    expect($user->fresh()->account_type)->toBe(AccountType::USER);
});

test('a refused promotion revokes no token', function (): void {
    $user = makeAccount(['email' => 'keeps-tokens@example.test']);
    $user->createToken('session', ['user:access']);
    attachSocialIdentity($user);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertStatus(409);

    expect($user->tokens()->count())->toBe(1);
});

test('an administrator promoted again stays an administrator, and nothing is recorded', function (): void {
    $admin = makeAccount(['email' => 'already-admin@example.test', 'account_type' => AccountType::ADMIN]);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$admin->id.'/promote')->assertOk();

    expect($admin->fresh()->account_type)->toBe(AccountType::ADMIN)
        ->and(promotionRecords(AuditAction::ACCOUNT_PROMOTED))->toBe([])
        ->and(promotionRecords(AuditAction::ACCOUNT_PROMOTION_REFUSED))->toBe([]);
});

// ── What is recorded ─────────────────────────────────────────────────────────

test('the refusal is recorded with the count and without the identity', function (): void {
    $user = makeAccount(['email' => 'recorded@example.test']);
    $identity = attachSocialIdentity($user);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertStatus(409);

    $records = promotionRecords(AuditAction::ACCOUNT_PROMOTION_REFUSED);

    expect($records)->toHaveCount(1)
        ->and($records[0]['subject'])->toBe($user->id)
        ->and($records[0]['outcome'])->toBe('failed')
        ->and($records[0]['context'])->toBe(['reason' => 'social_identity', 'linked_identities' => 1])
        ->and(json_encode($records))->not->toContain($identity->provider_subject);
});

test('the refusal outlives the rollback of the promotion it refused', function (): void {
    // The promotion runs in a transaction the refusal rolls back. Its record is written
    // after, so the attempt is on file even though nothing it describes was committed.
    $user = makeAccount(['email' => 'rolled-back@example.test']);
    attachSocialIdentity($user);

    $this->withToken($this->token)->postJson('/api/v1/admin/users/'.$user->id.'/promote')->assertStatus(409);

    expect(promotionRecords(AuditAction::ACCOUNT_PROMOTION_REFUSED))->toHaveCount(1)
        ->and(promotionRecords(AuditAction::ACCOUNT_PROMOTED))->toBe([])
        ->and(DB::table('users')->where('id', $user->id)->value('account_type'))->toBe('user');
});

// ── What the admin API reports ───────────────────────────────────────────────

test('the admin API reports only the identities linked today', function (): void {
    $linked = makeAccount(['email' => 'reports-linked@example.test']);
    attachSocialIdentity($linked, 'google');

    $history = makeAccount(['email' => 'reports-history@example.test']);
    attachSocialIdentity($history, 'google', linked: false);

    $none = makeAccount(['email' => 'reports-none@example.test']);

    $show = fn (User $user) => $this->withToken($this->token)->getJson('/api/v1/admin/users/'.$user->id)->assertOk();

    $show($linked)
        ->assertJsonPath('data.has_linked_social_identity', true)
        ->assertJsonPath('data.linked_social_providers', ['google']);

    $show($history)
        ->assertJsonPath('data.has_linked_social_identity', false)
        ->assertJsonPath('data.linked_social_providers', []);

    $show($none)->assertJsonPath('data.has_linked_social_identity', false);
});

test('the admin API stops reporting an identity once it is unlinked', function (): void {
    $user = makeAccount(['email' => 'reports-after-unlink@example.test']);
    $identity = attachSocialIdentity($user);

    $this->withToken($this->token)->getJson('/api/v1/admin/users/'.$user->id)
        ->assertJsonPath('data.has_linked_social_identity', true);

    app(SocialIdentityRegistryContract::class)->unlink($user, $identity->id);

    $body = $this->withToken($this->token)->getJson('/api/v1/admin/users/'.$user->id)
        ->assertJsonPath('data.has_linked_social_identity', false)
        ->content();

    expect($body)->not->toContain($identity->provider_subject);
});
