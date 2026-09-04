# ADR 0029: Deferred Technical Hardening Backlog

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-04 — CI added as item 2 after Phase 9; item 2 closed by Phase 11, item 5 closed at level 5 in its follow-up

## Context

Across Phases 4 to 9 a number of issues were found, judged real, and deliberately not fixed in the phase that found them — because each belonged to a different concern than the one under review, and widening a phase to absorb them would have made it harder to gate rather than safer.

That reasoning holds only while the deferrals are remembered. Until now they existed solely in review conversation, which means they were one context loss away from becoming things nobody had decided about at all. This record makes the backlog durable: an item here has been examined, judged worth doing, and consciously scheduled later — it is not something that was missed.

Nothing here is a blocker for the phase that deferred it. Each entry states what the issue is, why it was deferred, and what would close it.

## Decision

Track the following as deferred hardening. An item leaves this list by being implemented, or by being explicitly reclassified as won't-do with a reason.

### 1. Architecture rules do not see route files

Pest's architecture tests analyse classes. A module's `Routes/api.php` is a plain script, so imports there are invisible to `toUse` rules. Phase 9 found `Notification/Routes/api.php` importing `Auth\Enums\TokenAbility` — a dependency the rules forbid — and the rules passed. It was found by reading, not by testing.

*Deferred because*: the immediate violation was removed, and building a checker is a tooling task rather than a notification one.

*Closed by*: a check that parses `app/Modules/*/Routes/*.php` for cross-module imports and asserts them against the same boundaries the class rules use.

### 2. No automated CI runs on a pull request — CLOSED (Phase 11)

Nothing runs on GitHub when a pull request is opened. `statusCheckRollup` on PR #8 was empty, and every green figure reported at a phase gate — tests on both engines, architecture rules, Pint, the security scans — came from a developer machine. `MERGEABLE` on a pull request is a statement about conflicts and nothing more.

This has been the arrangement since Phase 2 and the reporting has held up, but it rests on the person running the gate reporting it faithfully and on the gate being run at all. Two failures caught during Phase 10 illustrate the exposure: a security scan whose helper was silently broken and reported clean, and an architecture rule that passed because it guarded a namespace nothing imported. Both were found by running a positive control by hand. Neither would have been caught by a rule that says the tests passed.

*Deferred because*: CI is infrastructure work rather than application work, and folding it into a feature phase would gate that phase on a pipeline as well as on code.

*Closed by*: a GitHub Actions workflow running on every pull request — the suite against PostgreSQL and against SQLite, the architecture rules, Pint, and the security scans — with the result required before merge. The scans must carry positive controls, so a check that has stopped detecting anything fails rather than reporting clean.

*Closed by*: `.github/workflows/ci.yml` in Phase 11. The suite runs on PostgreSQL and on SQLite, plus architecture rules, Pint, whitespace, the secret scan and a from-scratch Docker build, all through `scripts/gate.sh` so CI and a developer issue the same commands. The scan carries positive controls and fails as unproven rather than clean. Preparing the Docker gate found the first thing it would have caught: both Dockerfiles had been unbuildable since Phase 1. ADR 0021 records which gates are live and which wait on Phases 12 and 13.

### 3. `integration_usage_logs` grows without bound

Every send attempt writes a row, permanently. There is no pruning, retention window, or archival.

*Deferred because*: the table is correct and useful as built; growth is an operational concern that no volume yet justifies.

*Closed by*: a retention policy with a scheduled prune, sized against what the usage endpoint and any future reporting actually need.

### 4. Central API rate limiting

ADR 0022 requires composite rate limiting. What exists is endpoint-specific: login, MFA challenge, and MFA delivery are throttled from the Settings module. The `api` middleware group itself has no throttle, so every other endpoint — including the public settings and language endpoints — is unlimited.

*Deferred because*: it belongs in Core as a platform concern, and patching it into whichever module happened to be under review would have put it in the wrong place.

*Closed by*: a Core rate limiter applied to the `api` group, configured through Settings as the auth throttles already are, without weakening the endpoint-specific limits that exist.

### 5. No static analysis — CLOSED at level 5 (follow-up to Phase 11)

PHPStan or Larastan is not installed. Two defects that reached review would have been caught by it: the implicit array-to-string conversion in Phase 4's `serializeValue`, and the unused `$type` parameter on `SettingService::set()`.

Measured in Phase 11 against this codebase: **96 errors at level 8**, 31 at level 5. By module at level 8: Auth 24, Localization 13, Settings 13, Notification 12, Media 10, Integration 9, User 7, Core 5, Authorization 3. Roughly half are Eloquent scope and builder typing; 18 concern `User|null` where middleware guarantees a user the type system cannot see, 14 of them in one controller.

*Deferred because*: introducing static analysis to a codebase of this size produces a baseline that wants its own review pass, which is a phase of work rather than an aside.

*Closed by*: Larastan v3.11 at **level 5**, enforced by `scripts/gate.sh stan` and required on every pull request. All 31 findings were fixed; no baseline, no `ignoreErrors`, no `@phpstan-ignore`. The gate was proved by planting a lost-model-type regression and confirming it fails.

Two of the 31 were not type noise. `EnsureUserIsAdmin` gated the admin perimeter on `method_exists($user, 'isAdmin')`, which accepts any object carrying a method of that name — and the perimeter tests showed anonymous fixtures walking through it. It now requires a `Core\Contracts\AdminIdentity` implementation, which `config/auth.php` makes meaningful because the user model is resolved from `env('AUTH_MODEL')` rather than fixed. In `MfaController`, an `instanceof PersonalAccessToken` check that analysis called redundant turned out to be load-bearing: `config/sanctum.php` sets `guard => ['web']`, so a session-authenticated caller carries a `TransientToken` whose `can()` returns true for every ability, and without the check the endpoint would have minted a full access token for a session that never held an enrolment credential. Both now have regression tests that fail when the guard is removed.

Three others were genuinely dead defensive code, and a fourth surfaced only once a vague `array<string, mixed>` was replaced with the real shape.

**Still open**: level 8 reports 65 further findings, most of them Eloquent generics and `User|null` where middleware guarantees a user the type system cannot see. Raising the level is its own hardening phase, and this entry stays on the list until it happens.

### 6. `helpers.php` is loaded by the service provider

The Settings module loads its `setting()` helper with `require_once` inside `register()`, rather than through Composer's `autoload.files`. The helper is therefore unavailable to anything that boots earlier.

*Deferred because*: the alternative couples the root `composer.json` to a specific module, which trades one flaw for another. The current arrangement works and nothing has needed the helper earlier.

*Closed by*: a decision on which coupling is preferable, recorded wherever the module structure is described.

### 7. Timestamp type inconsistency

`users`, `settings`, `mfa_methods`, `languages`, `integration_*` and `notification_*` use `timestampsTz`. Spatie's `permissions` and `roles` tables use `timestamps`, because they came from a published vendor migration.

*Deferred because*: the two tables carry no time-sensitive logic, and changing a vendor migration to fix a cosmetic inconsistency is a poor trade on its own.

*Closed by*: a migration altering both columns, most sensibly bundled with other work touching those tables.

### 8. Application-level authorization for regular users

Spatie RBAC is administrative infrastructure and only `account_type = admin` participates (ADR 0028). Regular users have no authorization system: no user groups, workspace membership, or application permissions. No speculative tables were created for any of it.

*Deferred because*: nothing consumes it yet, and inventing the schema before a consumer exists would fix the shape before the requirements are known.

*Closed by*: a phase with an actual application-authorization requirement. It must stay conceptually separate from admin RBAC.

### 9. Integration capabilities with no consumer

The Integration module implements SMS only. Email, WhatsApp, storage and AI were named in ADR 0017 but not built.

*Deferred because*: each would have been a seam with nothing on the other side of it. The manager pattern makes adding one a driver method plus a table row.

*Closed by*: the phase that first needs each. WhatsApp OTP as a multi-factor method (ADR 0013) and WhatsApp or push notification channels (ADR 0019) are both waiting on this.

### 10. Container log file permissions

`storage/logs/laravel.log` is not writable inside the backend container under the Windows bind mount. An application error becomes a wall of Monolog failures, which slowed diagnosis of a genuine bug during Phase 6.

*Deferred because*: it is a local Docker environment issue, not application code, and it does not affect correctness.

*Closed by*: fixing ownership or permissions on the mounted `storage` directory in the container image or compose configuration.

## Consequences

The backlog is reviewable and survives the conversations that produced it. Each item can be scheduled on its merits rather than resurfacing as a fresh discovery in a later review.

The risk this record carries is the ordinary one for any backlog: that listing an item comes to feel like addressing it. Item 2 closed in Phase 11, which is the shape this list is meant to have: an entry leaves by being built, not by being forgotten. Item 5 closed at level 5 in the phase after Phase 11, which leaves item 4 — unlimited public endpoints — as the entry most likely to cost something real if it stays deferred indefinitely.
