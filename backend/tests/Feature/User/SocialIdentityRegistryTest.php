<?php

declare(strict_types=1);

use App\Modules\User\Contracts\SocialIdentityRegistryContract;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Exceptions\SocialIdentityException;
use App\Modules\User\Models\SocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Linking and unlinking social identities (ADR 0050 §5), and what is recorded about it
 * (ADR 0037, extension of 2026-09-13).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

function socialRegistry(): SocialIdentityRegistryContract
{
    return app(SocialIdentityRegistryContract::class);
}

/**
 * The reason the registry refused a call, or null when it did not.
 */
function registryRefusal(callable $call): ?string
{
    try {
        $call();

        return null;
    } catch (SocialIdentityException $e) {
        return $e->reason;
    }
}

/**
 * @return array<int, array{subject: string|null, outcome: string, context: array<string, mixed>|null}>
 */
function recordedAudit(string $action): array
{
    return DB::table('audit_records')
        ->where('action', $action)
        ->orderBy('created_at')
        ->get()
        ->map(static fn (object $row): array => [
            'subject' => $row->subject,
            'outcome' => $row->outcome,
            'context' => is_string($row->context) ? json_decode($row->context, true) : null,
        ])
        ->all();
}

// ── Linking ──────────────────────────────────────────────────────────────────

test('linking records the identity, and says so without naming the person', function (): void {
    $user = makeAccount(['email' => 'links@example.test']);

    $link = socialRegistry()->link($user, 'google', 'subject-that-identifies-a-person', 'Person@Example.test');

    $records = recordedAudit('account.social_linked');

    expect($link->relinked)->toBeFalse()
        ->and($link->alreadyLinked)->toBeFalse()
        ->and($link->identity->isLinked())->toBeTrue()
        ->and($records)->toHaveCount(1)
        ->and($records[0]['subject'])->toBe($user->id)
        ->and($records[0]['outcome'])->toBe('succeeded')
        ->and($records[0]['context'])->toBe([
            'provider' => 'google',
            'identity_id' => $link->identity->id,
            'relinked' => false,
        ])
        ->and(json_encode($records))->not->toContain('subject-that-identifies-a-person')
        ->and(json_encode($records))->not->toContain('Person@Example.test');
});

test('linking an identity the account already holds changes and records nothing', function (): void {
    $user = makeAccount(['email' => 'links-twice@example.test']);

    socialRegistry()->link($user, 'google', 'same-subject', null);
    $again = socialRegistry()->link($user, 'google', 'same-subject', null);

    expect($again->alreadyLinked)->toBeTrue()
        ->and(SocialIdentity::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(recordedAudit('account.social_linked'))->toHaveCount(1);
});

test('an administrator cannot link an identity', function (): void {
    $admin = makeAccount(['email' => 'admin-links@example.test', 'account_type' => AccountType::ADMIN]);

    expect(registryRefusal(fn () => socialRegistry()->link($admin, 'google', 'admin-subject', null)))
        ->toBe(SocialIdentityException::ADMINISTRATOR)
        ->and(SocialIdentity::query()->where('user_id', $admin->id)->count())->toBe(0);
});

test('an identity linked to another account cannot be linked to this one', function (): void {
    $owner = makeAccount(['email' => 'owner@example.test']);
    $other = makeAccount(['email' => 'other@example.test']);

    socialRegistry()->link($owner, 'google', 'owned-subject', null);

    expect(registryRefusal(fn () => socialRegistry()->link($other, 'google', 'owned-subject', null)))
        ->toBe(SocialIdentityException::IDENTITY_IN_USE);
});

test('an identity unlinked from another account stays reserved to that account', function (): void {
    $owner = makeAccount(['email' => 'reserved-owner@example.test']);
    $other = makeAccount(['email' => 'reserved-other@example.test']);

    $identity = socialRegistry()->link($owner, 'google', 'reserved-subject', null)->identity;
    socialRegistry()->unlink($owner, $identity->id);

    expect(registryRefusal(fn () => socialRegistry()->link($other, 'google', 'reserved-subject', null)))
        ->toBe(SocialIdentityException::IDENTITY_IN_USE)
        ->and(SocialIdentity::query()->where('provider_subject', 'reserved-subject')->pluck('user_id')->all())
        ->toBe([$owner->id]);
});

test('an account cannot link a second identity from the same provider', function (): void {
    $user = makeAccount(['email' => 'second-from-provider@example.test']);

    socialRegistry()->link($user, 'google', 'first-subject', null);

    expect(registryRefusal(fn () => socialRegistry()->link($user, 'google', 'second-subject', null)))
        ->toBe(SocialIdentityException::PROVIDER_ALREADY_LINKED);
});

test('re-linking reuses the historical row and records that it was a re-link', function (): void {
    $user = makeAccount(['email' => 'relinks@example.test']);

    $first = socialRegistry()->link($user, 'google', 'returning-subject', null)->identity;
    socialRegistry()->unlink($user, $first->id);

    $again = socialRegistry()->link($user, 'google', 'returning-subject', null);
    $records = recordedAudit('account.social_linked');

    expect($again->relinked)->toBeTrue()
        ->and($again->identity->id)->toBe($first->id)
        ->and($again->identity->isLinked())->toBeTrue()
        ->and(SocialIdentity::query()->where('provider_subject', 'returning-subject')->count())->toBe(1)
        ->and($records)->toHaveCount(2)
        ->and($records[1]['context']['relinked'])->toBeTrue();
});

// ── Unlinking ────────────────────────────────────────────────────────────────

test('unlinking keeps the row, and records how many identities remain linked', function (): void {
    $user = makeAccount(['email' => 'unlinks@example.test']);

    $google = socialRegistry()->link($user, 'google', 'google-subject', null)->identity;
    socialRegistry()->link($user, 'example', 'example-subject', null);

    $unlinked = socialRegistry()->unlink($user, $google->id);
    $records = recordedAudit('account.social_unlinked');

    expect($unlinked->isLinked())->toBeFalse()
        ->and(SocialIdentity::query()->whereKey($google->id)->exists())->toBeTrue()
        ->and($records)->toHaveCount(1)
        ->and($records[0]['context'])->toBe([
            'provider' => 'google',
            'identity_id' => $google->id,
            'linked_remaining' => 1,
        ]);
});

test('the only way to sign in to an account cannot be unlinked', function (): void {
    $user = makeAccount(['email' => 'last-method@example.test']);
    DB::table('users')->where('id', $user->id)->update(['password' => null]);

    $identity = socialRegistry()->link($user, 'google', 'only-subject', null)->identity;

    expect(registryRefusal(fn () => socialRegistry()->unlink($user->fresh(), $identity->id)))
        ->toBe(SocialIdentityException::LAST_SIGN_IN_METHOD)
        ->and($identity->fresh()->isLinked())->toBeTrue()
        ->and(recordedAudit('account.social_unlinked'))->toBe([]);
});

test('an account with a password may unlink its last identity', function (): void {
    $user = makeAccount(['email' => 'has-password-unlinks@example.test']);

    $identity = socialRegistry()->link($user, 'google', 'last-subject', null)->identity;

    expect(registryRefusal(fn () => socialRegistry()->unlink($user, $identity->id)))->toBeNull()
        ->and(recordedAudit('account.social_unlinked')[0]['context']['linked_remaining'])->toBe(0);
});

test('an account without a password may unlink one identity while another remains', function (): void {
    $user = makeAccount(['email' => 'two-identities@example.test']);
    DB::table('users')->where('id', $user->id)->update(['password' => null]);

    $google = socialRegistry()->link($user, 'google', 'google-only-subject', null)->identity;
    socialRegistry()->link($user, 'example', 'example-only-subject', null);

    expect(registryRefusal(fn () => socialRegistry()->unlink($user->fresh(), $google->id)))->toBeNull();
});

test('another account’s identity cannot be unlinked through this one', function (): void {
    $owner = makeAccount(['email' => 'unlink-owner@example.test']);
    $other = makeAccount(['email' => 'unlink-other@example.test']);

    $identity = socialRegistry()->link($owner, 'google', 'not-yours', null)->identity;

    expect(registryRefusal(fn () => socialRegistry()->unlink($other, $identity->id)))
        ->toBe(SocialIdentityException::NOT_LINKED)
        ->and($identity->fresh()->isLinked())->toBeTrue();
});

test('an identity that is already unlinked cannot be unlinked again', function (): void {
    $user = makeAccount(['email' => 'unlink-twice@example.test']);

    $identity = socialRegistry()->link($user, 'google', 'twice-subject', null)->identity;
    socialRegistry()->unlink($user, $identity->id);

    expect(registryRefusal(fn () => socialRegistry()->unlink($user, $identity->id)))
        ->toBe(SocialIdentityException::NOT_LINKED)
        ->and(recordedAudit('account.social_unlinked'))->toHaveCount(1);
});

test('using an identity to sign in records when', function (): void {
    $user = makeAccount(['email' => 'used@example.test']);
    $identity = socialRegistry()->link($user, 'google', 'used-subject', null)->identity;

    expect($identity->last_used_at)->toBeNull();

    socialRegistry()->recordUse($identity);

    expect($identity->fresh()->last_used_at)->not->toBeNull();
});
