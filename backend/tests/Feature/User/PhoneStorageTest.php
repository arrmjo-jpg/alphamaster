<?php

declare(strict_types=1);

use App\Modules\User\Exceptions\InvalidPhoneNumberException;
use App\Modules\User\Models\User;
use App\Modules\User\Support\PhoneNumber;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * One number, written the several ways a person actually writes it.
 */
const PHONE_CANONICAL = '+962790000000';

// ─── Canonicalisation ────────────────────────────────────────────────────────

it('reduces the ways a number is written to one canonical value', function (string $written): void {
    expect(PhoneNumber::canonicalise($written))->toBe(PHONE_CANONICAL);
})->with([
    'already canonical' => PHONE_CANONICAL,
    'spaced' => '+962 79 000 0000',
    'hyphenated' => '+962-79-000-0000',
    'parenthesised' => '+962 (79) 000-0000',
    'dotted' => '+962.79.000.0000',
    'tabbed and padded' => "  +962\t79 000 0000  ",
    'non-breaking spaces' => "+962\u{00A0}79\u{00A0}000\u{00A0}0000",
    'pasted with a directional mark' => "\u{200E}+962790000000",
]);

it('treats an absent number as absent rather than as an error', function (?string $written): void {
    expect(PhoneNumber::canonicalise($written))->toBeNull();
})->with([
    'null' => null,
    'empty string' => '',
    'separators only' => '  - () ',
]);

it('refuses a value it cannot read as E.164 rather than repairing it', function (string $written): void {
    expect(fn () => PhoneNumber::canonicalise($written))
        ->toThrow(InvalidPhoneNumberException::class);
})->with([
    'no country code' => '0790000000',
    'country code starting with zero' => '+0790000000',
    'too short' => '+9627900',
    'too long, sixteen digits' => '+9627900000000000',
    'letters' => '+962-CALL-ME',
    'plus in the middle' => '962+790000000',
    'two plus signs' => '++962790000000',
]);

it('does not name the rejected number in the message it raises', function (): void {
    // The exception reaches logs. A phone number is personal data and does not belong
    // in one merely because it was mistyped.
    try {
        PhoneNumber::canonicalise('0790000000');
        $this->fail('The malformed number was accepted.');
    } catch (InvalidPhoneNumberException $e) {
        expect($e->getMessage())->not->toContain('0790000000');
    }
});

// ─── The lookup hash ─────────────────────────────────────────────────────────

it('derives one hash for one number, however that number was written', function (): void {
    $fromCanonical = PhoneNumber::lookupHash((string) PhoneNumber::canonicalise(PHONE_CANONICAL));
    $fromSpaced = PhoneNumber::lookupHash((string) PhoneNumber::canonicalise('+962 79 000 0000'));

    expect($fromSpaced)->toBe($fromCanonical);
});

it('derives different hashes for different numbers', function (): void {
    expect(PhoneNumber::lookupHash('+962790000001'))
        ->not->toBe(PhoneNumber::lookupHash(PHONE_CANONICAL));
});

it('keys the hash, so it is not a digest anyone can precompute', function (): void {
    // The search space for a phone number is small enough that an unkeyed digest of
    // one is barely a digest. This asserts the key and the label are both in play,
    // which an unkeyed sha256 would fail.
    expect(PhoneNumber::lookupHash(PHONE_CANONICAL))
        ->not->toBe(hash('sha256', PHONE_CANONICAL))
        ->toHaveLength(64);
});

it('refuses to hash a number that has not been canonicalised', function (): void {
    // Hashing an uncanonicalised value yields a valid-looking digest that is the
    // wrong one — and a wrong digest in a unique column is a duplicate the constraint
    // cannot see.
    expect(fn () => PhoneNumber::lookupHash('+962 79 000 0000'))
        ->toThrow(InvalidPhoneNumberException::class);
});

// ─── Storage ─────────────────────────────────────────────────────────────────

it('stores the canonical number and its hash together', function (): void {
    $user = makeAccount(['email' => 'stored@example.com', 'phone' => '+962 79 000 0000']);

    expect($user->phone)->toBe(PHONE_CANONICAL)
        ->and($user->phone_hash)->toBe(PhoneNumber::lookupHash(PHONE_CANONICAL));

    $user->refresh();

    expect($user->phone)->toBe(PHONE_CANONICAL)
        ->and($user->phone_hash)->toBe(PhoneNumber::lookupHash(PHONE_CANONICAL));
});

it('keeps the hash in step when the number changes', function (): void {
    $user = makeAccount(['email' => 'changing@example.com', 'phone' => PHONE_CANONICAL]);

    $user->phone = '+962 79 000 0001';
    $user->save();
    $user->refresh();

    expect($user->phone)->toBe('+962790000001')
        ->and($user->phone_hash)->toBe(PhoneNumber::lookupHash('+962790000001'));
});

it('clears the hash when the number is removed', function (): void {
    $user = makeAccount(['email' => 'clearing@example.com', 'phone' => PHONE_CANONICAL]);

    $user->phone = null;
    $user->save();
    $user->refresh();

    expect($user->phone)->toBeNull()
        ->and($user->phone_hash)->toBeNull();
});

it('never accepts a hash from outside', function (): void {
    // phone_hash is derived, so it is not fillable. If it ever became fillable, a
    // caller could store a hash describing a different number than the row holds, and
    // the unique constraint would be guarding a value the row does not have.
    expect((new User)->getFillable())->not->toContain('phone_hash');

    // Model::shouldBeStrict() is active outside production, so the attempt is refused
    // rather than quietly dropped. Asserted as well as the fillable list because the
    // list is the guarantee and this is the alarm: in production the same write is
    // discarded silently, which is why the list is what the test above pins.
    expect(fn () => new User([
        'name' => 'Forged',
        'email' => 'forged@example.com',
        'phone_hash' => 'forged',
    ]))->toThrow(MassAssignmentException::class);
});

it('does not publish the hash when the model is serialized', function (): void {
    $user = makeAccount(['email' => 'serialized@example.com', 'phone' => PHONE_CANONICAL]);

    expect($user->toArray())->not->toHaveKey('phone_hash')
        ->and($user->toArray())->toHaveKey('phone');
});

// ─── The unique constraint ───────────────────────────────────────────────────

it('refuses a second account claiming the same number, written differently', function (): void {
    makeAccount(['email' => 'first@example.com', 'phone' => PHONE_CANONICAL]);

    // The written form differs; the number does not. Canonicalisation, the hash and
    // the constraint have to agree for this to be refused, so this one assertion
    // exercises all three.
    expect(fn () => makeAccount(['email' => 'second@example.com', 'phone' => '+962 79 000 0000']))
        ->toThrow(QueryException::class);
});

it('allows many accounts to have no number at all', function (): void {
    // A unique index tolerates any number of NULLs on both engines. Without that, an
    // optional phone would make the second account on the platform impossible.
    makeAccount(['email' => 'none-a@example.com']);
    makeAccount(['email' => 'none-b@example.com']);
    makeAccount(['email' => 'none-c@example.com', 'phone' => null]);

    expect(User::query()->whereNull('phone_hash')->count())->toBe(3);
});

// ─── Lookup ──────────────────────────────────────────────────────────────────

it('finds an account by any written form of its number', function (): void {
    $user = makeAccount(['email' => 'findable@example.com', 'phone' => PHONE_CANONICAL]);

    expect(User::findByPhone('+962 79 000 0000')?->id)->toBe($user->id)
        ->and(User::findByPhone('+962-79-000-0000')?->id)->toBe($user->id)
        ->and(User::findByPhone(PHONE_CANONICAL)?->id)->toBe($user->id);
});

it('reports no match rather than raising, for anything it cannot read', function (?string $value): void {
    makeAccount(['email' => 'present@example.com', 'phone' => PHONE_CANONICAL]);

    expect(User::findByPhone($value))->toBeNull();
})->with([
    'an unknown number' => '+962790000009',
    'not a number at all' => 'someone@example.com',
    'malformed' => '0790000000',
    'empty' => '',
    'null' => null,
]);
