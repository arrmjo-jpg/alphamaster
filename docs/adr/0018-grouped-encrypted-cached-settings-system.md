# ADR 0018: Grouped, Encrypted, and Cached Settings System

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — aligned with the implemented Phase 4 contract

## Context

Application configuration must be dynamically modifiable by administrators while keeping secrets secure and reads ultra-fast.

The original record described an intended design. Implementing it surfaced three points where the intent could not survive contact with the requirements, and this record has been revised to state what the module actually does, because an ADR that disagrees with the code is worse than no ADR. Laravel's `encrypted` cast applies to a column unconditionally, but only the rows flagged `is_secret` are encrypted here, so the cast cannot express the rule. Cache tags would let a single secret's plaintext share a tagged set with public values, and the segmentation the module needs is a property of the key layout rather than of tag membership. The `/public` suffix in the original endpoint added a path segment that carried no information the resource did not already imply.

## Decision

Settings live in a typed, grouped key-value table. Every value is stored as a canonical string alongside its declared `SettingType`, and conversion in both directions is strict: a value that cannot be represented exactly in its declared type is rejected rather than coerced.

**Encryption.** Secret values are encrypted with `Crypt::encryptString()` and read back with `Crypt::decryptString()`, driven by the `is_secret` flag through the model's value lifecycle (`setRawValue()` / `getRawValue()`) rather than by an Eloquent cast. A failed decrypt raises `SettingDecryptionException`; the stored ciphertext is never returned in place of plaintext. A secret that has not been provisioned is `null` — a legitimate state distinct from both an empty string and a decrypt failure — and the admin API renders an unset secret as `null` while rendering a provisioned one as a mask. The seeder provisions secrets unset; generating or rotating secret material is an operator action, never a side effect of seeding.

**Caching.** Settings are cached in Redis under segmented, explicitly named keys, without cache tags. Public payloads, per-group public payloads, the list of groups exposing public settings, and the internal per-group index each occupy their own key and are invalidated by explicit `forget` calls. Decrypted secrets are never written to any cache entry: the internal group index carries non-secret values plus the *names* of the secret keys, and a secret is read from the database on demand. Invalidation runs after the database transaction commits (`DB::afterCommit()`), never inside it, so a concurrent reader cannot repopulate a key from uncommitted state and pin a stale value for the full TTL. A cache entry whose shape does not match the current contract is treated as a miss and rebuilt.

**Public API.** Public settings are exposed as:

* `GET /api/v1/settings` — all public settings, grouped by group name.
* `GET /api/v1/settings/{group}` — public settings for one group.

There is no `/public` alias. Responses carry keys and typed values only, with no metadata flags. A group that exposes no public settings is reported as `404`, which keeps internal groups indistinguishable from absent ones. The `{group}` parameter is constrained to a lowercase identifier and checked against the known public groups before any cache write, so an arbitrary path segment cannot mint a cache entry.

**Administration.** Settings are provisioned by migrations and seeders; the admin API updates existing settings but never creates them, so an unknown group or key is a `404` rather than a validation error. Both invariants — that a secret is never public, and that `type` holds a known `SettingType` value — are enforced by database constraints, not by model events, so they survive `WithoutModelEvents` and raw query-builder writes.

## Consequences

Provides instant settings retrieval without database queries and protects credentials at rest, at the cost of one database read per secret access, which is the price of keeping plaintext out of Redis entirely.

Because the invariants are enforced in the database, they are engine-level behaviour and must be verified on PostgreSQL; see ADR 0027, which makes PostgreSQL authoritative for test execution and explains why a green SQLite run does not prove them. Where the local SQLite harness needs the same guarantees, they are reproduced with triggers whose only purpose is to let the test suite exercise the behaviour — PostgreSQL remains the sole production engine under ADR 0003.
