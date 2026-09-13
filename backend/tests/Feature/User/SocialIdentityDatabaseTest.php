<?php

declare(strict_types=1);

use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The social identity invariants, asserted against the database engine (ADR 0050).
 *
 * Every write here goes through the query builder rather than a model, so each assertion
 * is about what the storage refuses — the promise that holds against a console command,
 * a restore or a raw statement, not only through a service that remembered to check.
 *
 * A refusal is asserted by *which* rule refused it, not merely that something did. A
 * test that only expected an exception would pass just as well if a duplicate address
 * or a missing foreign key had thrown it, and would go on passing after the trigger it
 * names had been removed.
 */

uses(RefreshDatabase::class);

/** The identity trigger: a linked identity on an administrator. */
const SOCIAL_RULE_NOT_ADMIN = '/chk_social_identity_not_admin/';

/** The users trigger: promotion while an identity is linked. */
const SOCIAL_RULE_PROMOTION = '/chk_users_admin_without_linked_social_identity/';

/**
 * A subject claimed twice. PostgreSQL names the index; SQLite names its columns.
 */
const SOCIAL_RULE_SUBJECT = '/uniq_social_identity_subject|social_identities\.provider, social_identities\.provider_subject/';

/**
 * A second linked identity from one provider. PostgreSQL names the index; SQLite names
 * its columns, which are distinct from the subject index's.
 */
const SOCIAL_RULE_LINKED_PROVIDER = '/uniq_social_identity_linked_provider|social_identities\.user_id, social_identities\.provider/';

/**
 * Insert an identity row directly, and return its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertSocialIdentity(User $user, array $overrides = []): string
{
    $id = (string) Str::ulid();

    DB::table('social_identities')->insert(array_merge([
        'id' => $id,
        'user_id' => $user->id,
        'provider' => 'example-provider',
        'provider_subject' => 'subject-'.Str::lower(Str::random(16)),
        'email' => null,
        'linked_at' => now(),
        'last_used_at' => null,
        'unlinked_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

/**
 * Run a statement and return the database's refusal, or null if it was accepted.
 *
 * Wrapped in a nested transaction, a savepoint, because PostgreSQL aborts the enclosing
 * transaction on any constraint violation; without it every assertion after an expected
 * refusal would fail for that reason instead of running.
 */
function socialIdentityRefusal(callable $statement): ?string
{
    try {
        DB::transaction(static fn () => $statement());

        return null;
    } catch (QueryException $e) {
        return $e->getMessage();
    }
}

function socialIdentityAccount(string $email, AccountType $type = AccountType::USER): User
{
    return makeAccount(['email' => $email, 'account_type' => $type]);
}

function setAccountTypeDirectly(User $user, string $type): void
{
    DB::table('users')->where('id', $user->id)->update(['account_type' => $type]);
}

// ── A, B: a user may hold identities ─────────────────────────────────────────

test('A: a user may hold a linked identity', function (): void {
    $user = socialIdentityAccount('linked-user@example.com');

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($user)))->toBeNull()
        ->and(DB::table('social_identities')->where('user_id', $user->id)->whereNull('unlinked_at')->count())->toBe(1);
});

test('B: a user may hold an unlinked identity', function (): void {
    $user = socialIdentityAccount('unlinked-user@example.com');

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($user, ['unlinked_at' => now()])))->toBeNull()
        ->and(DB::table('social_identities')->where('user_id', $user->id)->whereNotNull('unlinked_at')->count())->toBe(1);
});

// ── C: an administrator may not hold a linked identity ───────────────────────

test('C: an administrator cannot be given a linked identity', function (): void {
    $admin = socialIdentityAccount('linked-admin@example.com', AccountType::ADMIN);

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($admin)))->toMatch(SOCIAL_RULE_NOT_ADMIN)
        ->and(DB::table('social_identities')->where('user_id', $admin->id)->count())->toBe(0);
});

test('C: an administrator cannot re-link an unlinked identity kept from before promotion', function (): void {
    // The history ADR 0050 allows: the identity was unlinked while the account was a
    // user, and the account was promoted afterwards.
    $account = socialIdentityAccount('promoted-later@example.com');
    $identity = insertSocialIdentity($account, ['unlinked_at' => now()]);
    setAccountTypeDirectly($account, 'admin');

    $relink = fn () => DB::table('social_identities')->where('id', $identity)->update(['unlinked_at' => null]);

    expect(socialIdentityRefusal($relink))->toMatch(SOCIAL_RULE_NOT_ADMIN)
        ->and(DB::table('social_identities')->where('id', $identity)->value('unlinked_at'))->not->toBeNull();
});

test('C: the refusal counts linked state, so an administrator may keep unlinked history', function (): void {
    $admin = socialIdentityAccount('admin-history@example.com', AccountType::ADMIN);

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($admin, ['unlinked_at' => now()])))->toBeNull();
});

// ── D, E: promotion is blocked by a linked identity, and only by one ─────────

test('D: an account with a linked identity cannot be made an administrator', function (): void {
    $user = socialIdentityAccount('blocked-promotion@example.com');
    insertSocialIdentity($user);

    expect(socialIdentityRefusal(fn () => setAccountTypeDirectly($user, 'admin')))->toMatch(SOCIAL_RULE_PROMOTION)
        ->and(DB::table('users')->where('id', $user->id)->value('account_type'))->toBe('user');
});

test('D: one linked identity among unlinked ones still blocks promotion', function (): void {
    $user = socialIdentityAccount('mixed-identities@example.com');
    insertSocialIdentity($user, ['provider' => 'provider-one', 'unlinked_at' => now()]);
    insertSocialIdentity($user, ['provider' => 'provider-two']);

    expect(socialIdentityRefusal(fn () => setAccountTypeDirectly($user, 'admin')))->toMatch(SOCIAL_RULE_PROMOTION)
        ->and(DB::table('users')->where('id', $user->id)->value('account_type'))->toBe('user');
});

test('E: an account whose identities are all unlinked has no database obstacle to promotion', function (): void {
    $user = socialIdentityAccount('all-unlinked@example.com');
    insertSocialIdentity($user, ['provider' => 'provider-one', 'unlinked_at' => now()]);
    insertSocialIdentity($user, ['provider' => 'provider-two', 'unlinked_at' => now()]);

    expect(socialIdentityRefusal(fn () => setAccountTypeDirectly($user, 'admin')))->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('account_type'))->toBe('admin');
});

test('E: unlinking the last identity is what lifts the block', function (): void {
    $user = socialIdentityAccount('unlink-then-promote@example.com');
    $identity = insertSocialIdentity($user);

    expect(socialIdentityRefusal(fn () => setAccountTypeDirectly($user, 'admin')))->toMatch(SOCIAL_RULE_PROMOTION);

    DB::table('social_identities')->where('id', $identity)->update(['unlinked_at' => now()]);

    expect(socialIdentityRefusal(fn () => setAccountTypeDirectly($user, 'admin')))->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('account_type'))->toBe('admin');
});

test('the promotion rule does not touch account-type changes that are not a promotion', function (): void {
    // Any other write to an account with a linked identity is untouched.
    $user = socialIdentityAccount('other-writes@example.com');
    insertSocialIdentity($user);

    expect(socialIdentityRefusal(fn () => setAccountTypeDirectly($user, 'user')))->toBeNull()
        ->and(socialIdentityRefusal(
            fn () => DB::table('users')->where('id', $user->id)->update(['name' => 'Renamed'])
        ))->toBeNull();
});

// ── F, G: a subject belongs to one account, for good ─────────────────────────

test('F: the same provider subject cannot belong to a second account', function (): void {
    $first = socialIdentityAccount('first-owner@example.com');
    $second = socialIdentityAccount('second-owner@example.com');
    insertSocialIdentity($first, ['provider' => 'example-provider', 'provider_subject' => 'shared-subject']);

    $claim = fn () => insertSocialIdentity($second, ['provider' => 'example-provider', 'provider_subject' => 'shared-subject']);

    expect(socialIdentityRefusal($claim))->toMatch(SOCIAL_RULE_SUBJECT)
        ->and(DB::table('social_identities')->where('provider_subject', 'shared-subject')->count())->toBe(1);
});

test('F: the same subject under a different provider is a different identity', function (): void {
    $first = socialIdentityAccount('provider-a@example.com');
    $second = socialIdentityAccount('provider-b@example.com');
    insertSocialIdentity($first, ['provider' => 'provider-a', 'provider_subject' => '12345']);

    expect(socialIdentityRefusal(
        fn () => insertSocialIdentity($second, ['provider' => 'provider-b', 'provider_subject' => '12345'])
    ))->toBeNull();
});

test('G: an unlinked row keeps its subject reserved from every other account', function (): void {
    $original = socialIdentityAccount('original-owner@example.com');
    $other = socialIdentityAccount('would-be-owner@example.com');
    insertSocialIdentity($original, [
        'provider' => 'example-provider',
        'provider_subject' => 'reserved-subject',
        'unlinked_at' => now(),
    ]);

    $claimLinked = fn () => insertSocialIdentity($other, ['provider' => 'example-provider', 'provider_subject' => 'reserved-subject']);
    $claimUnlinked = fn () => insertSocialIdentity($other, [
        'provider' => 'example-provider',
        'provider_subject' => 'reserved-subject',
        'unlinked_at' => now(),
    ]);

    expect(socialIdentityRefusal($claimLinked))->toMatch(SOCIAL_RULE_SUBJECT)
        ->and(socialIdentityRefusal($claimUnlinked))->toMatch(SOCIAL_RULE_SUBJECT)
        ->and(DB::table('social_identities')->where('provider_subject', 'reserved-subject')->pluck('user_id')->all())
        ->toBe([$original->id]);
});

// ── H: re-linking reuses the historical row ──────────────────────────────────

test('H: the original account re-links by clearing unlinked_at on the same row', function (): void {
    $user = socialIdentityAccount('relinks@example.com');
    $identity = insertSocialIdentity($user, [
        'provider' => 'example-provider',
        'provider_subject' => 'returning-subject',
        'unlinked_at' => now(),
    ]);

    $relink = fn () => DB::table('social_identities')->where('id', $identity)->update([
        'unlinked_at' => null,
        'linked_at' => now(),
    ]);

    expect(socialIdentityRefusal($relink))->toBeNull()
        ->and(DB::table('social_identities')->where('provider_subject', 'returning-subject')->pluck('id')->all())
        ->toBe([$identity])
        ->and(DB::table('social_identities')->where('id', $identity)->value('unlinked_at'))->toBeNull();
});

test('H: a second row for the same subject cannot stand in for a re-link, even on the original account', function (): void {
    $user = socialIdentityAccount('no-replacement@example.com');
    insertSocialIdentity($user, [
        'provider' => 'example-provider',
        'provider_subject' => 'single-row-subject',
        'unlinked_at' => now(),
    ]);

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($user, [
        'provider' => 'example-provider',
        'provider_subject' => 'single-row-subject',
    ])))->toMatch(SOCIAL_RULE_SUBJECT)
        ->and(DB::table('social_identities')->where('provider_subject', 'single-row-subject')->count())->toBe(1);
});

// ── One linked identity per provider per account ─────────────────────────────

test('an account cannot hold two linked identities from the same provider', function (): void {
    $user = socialIdentityAccount('two-linked@example.com');
    insertSocialIdentity($user, ['provider' => 'example-provider', 'provider_subject' => 'first-subject']);

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($user, [
        'provider' => 'example-provider',
        'provider_subject' => 'second-subject',
    ])))->toMatch(SOCIAL_RULE_LINKED_PROVIDER);
});

test('an unlinked identity from a provider does not stop a new link from that provider', function (): void {
    $user = socialIdentityAccount('history-then-new@example.com');
    insertSocialIdentity($user, ['provider' => 'example-provider', 'provider_subject' => 'old-subject', 'unlinked_at' => now()]);

    expect(socialIdentityRefusal(fn () => insertSocialIdentity($user, [
        'provider' => 'example-provider',
        'provider_subject' => 'new-subject',
    ])))->toBeNull();
});

test('re-linking is refused while another identity from that provider is linked', function (): void {
    $user = socialIdentityAccount('relink-conflict@example.com');
    $old = insertSocialIdentity($user, ['provider' => 'example-provider', 'provider_subject' => 'old-subject', 'unlinked_at' => now()]);
    insertSocialIdentity($user, ['provider' => 'example-provider', 'provider_subject' => 'current-subject']);

    expect(socialIdentityRefusal(
        fn () => DB::table('social_identities')->where('id', $old)->update(['unlinked_at' => null])
    ))->toMatch(SOCIAL_RULE_LINKED_PROVIDER);
});

// ── Ownership and provider identity are immutable ────────────────────────────

/** The immutability trigger: a row re-pointed at another owner or identity. */
const SOCIAL_RULE_IMMUTABLE = '/chk_social_identity_immutable/';

test('an identity cannot be moved to another account', function (): void {
    $owner = socialIdentityAccount('immutable-owner@example.com');
    $other = socialIdentityAccount('immutable-other@example.com');
    $identity = insertSocialIdentity($owner);

    // The other account is a user with no identity from this provider, so neither the
    // administrator rule nor either uniqueness rule has a reason to refuse this — only
    // immutability does.
    $move = fn () => DB::table('social_identities')->where('id', $identity)->update(['user_id' => $other->id]);

    expect(socialIdentityRefusal($move))->toMatch(SOCIAL_RULE_IMMUTABLE)
        ->and(DB::table('social_identities')->where('id', $identity)->value('user_id'))->toBe($owner->id);
});

test('an unlinked identity cannot be moved to another account either', function (): void {
    $owner = socialIdentityAccount('immutable-unlinked-owner@example.com');
    $other = socialIdentityAccount('immutable-unlinked-other@example.com');
    $identity = insertSocialIdentity($owner, ['unlinked_at' => now()]);

    $move = fn () => DB::table('social_identities')->where('id', $identity)->update(['user_id' => $other->id]);

    expect(socialIdentityRefusal($move))->toMatch(SOCIAL_RULE_IMMUTABLE)
        ->and(DB::table('social_identities')->where('id', $identity)->value('user_id'))->toBe($owner->id);
});

test('an identity cannot be changed to a different provider', function (): void {
    $user = socialIdentityAccount('immutable-provider@example.com');
    $identity = insertSocialIdentity($user, ['provider' => 'provider-before']);

    $change = fn () => DB::table('social_identities')->where('id', $identity)->update(['provider' => 'provider-after']);

    expect(socialIdentityRefusal($change))->toMatch(SOCIAL_RULE_IMMUTABLE)
        ->and(DB::table('social_identities')->where('id', $identity)->value('provider'))->toBe('provider-before');
});

test('an identity cannot be changed to a different subject', function (): void {
    $user = socialIdentityAccount('immutable-subject@example.com');
    $identity = insertSocialIdentity($user, ['provider_subject' => 'subject-before']);

    $change = fn () => DB::table('social_identities')->where('id', $identity)->update(['provider_subject' => 'subject-after']);

    expect(socialIdentityRefusal($change))->toMatch(SOCIAL_RULE_IMMUTABLE)
        ->and(DB::table('social_identities')->where('id', $identity)->value('provider_subject'))->toBe('subject-before');
});

test('writing the owner and identity back unchanged is not a change', function (): void {
    // An ORM save sends every column; the rule must refuse a change, not a mention.
    $user = socialIdentityAccount('immutable-rewrite@example.com');
    $identity = insertSocialIdentity($user, ['provider' => 'same-provider', 'provider_subject' => 'same-subject']);

    $rewrite = fn () => DB::table('social_identities')->where('id', $identity)->update([
        'user_id' => $user->id,
        'provider' => 'same-provider',
        'provider_subject' => 'same-subject',
    ]);

    expect(socialIdentityRefusal($rewrite))->toBeNull();
});

test('the fields that describe an identity’s use remain writable', function (): void {
    $user = socialIdentityAccount('immutable-allowed@example.com');
    $identity = insertSocialIdentity($user, ['email' => 'before@example.com']);

    $linkedAt = now()->subDay()->startOfSecond();
    $usedAt = now()->subHour()->startOfSecond();
    $unlinkedAt = now()->startOfSecond();

    $update = fn () => DB::table('social_identities')->where('id', $identity)->update([
        'linked_at' => $linkedAt,
        'last_used_at' => $usedAt,
        'unlinked_at' => $unlinkedAt,
        'email' => 'after@example.com',
    ]);

    $row = fn () => DB::table('social_identities')->where('id', $identity)->first();

    expect(socialIdentityRefusal($update))->toBeNull()
        ->and($row()->email)->toBe('after@example.com')
        ->and($row()->last_used_at)->not->toBeNull()
        ->and($row()->unlinked_at)->not->toBeNull();
});

// ── What the table holds ─────────────────────────────────────────────────────

test('the table holds no provider token, secret or verification flag', function (): void {
    expect(Schema::getColumnListing('social_identities'))->toEqualCanonicalizing([
        'id', 'user_id', 'provider', 'provider_subject', 'email',
        'linked_at', 'last_used_at', 'unlinked_at', 'created_at', 'updated_at',
    ]);
});

test('deleting an account removes its identities', function (): void {
    $user = socialIdentityAccount('deleted-account@example.com');
    insertSocialIdentity($user);
    insertSocialIdentity($user, ['provider' => 'another-provider', 'unlinked_at' => now()]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('social_identities')->where('user_id', $user->id)->count())->toBe(0);
});
