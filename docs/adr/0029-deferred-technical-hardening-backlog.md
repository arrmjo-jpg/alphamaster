# ADR 0029: Deferred Technical Hardening Backlog

* **Status**: Accepted
* **Date**: 2026-09-03

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

### 2. `integration_usage_logs` grows without bound

Every send attempt writes a row, permanently. There is no pruning, retention window, or archival.

*Deferred because*: the table is correct and useful as built; growth is an operational concern that no volume yet justifies.

*Closed by*: a retention policy with a scheduled prune, sized against what the usage endpoint and any future reporting actually need.

### 3. Central API rate limiting

ADR 0022 requires composite rate limiting. What exists is endpoint-specific: login, MFA challenge, and MFA delivery are throttled from the Settings module. The `api` middleware group itself has no throttle, so every other endpoint — including the public settings and language endpoints — is unlimited.

*Deferred because*: it belongs in Core as a platform concern, and patching it into whichever module happened to be under review would have put it in the wrong place.

*Closed by*: a Core rate limiter applied to the `api` group, configured through Settings as the auth throttles already are, without weakening the endpoint-specific limits that exist.

### 4. No static analysis

PHPStan or Larastan is not installed. Two defects that reached review would have been caught by it: the implicit array-to-string conversion in Phase 4's `serializeValue`, and the unused `$type` parameter on `SettingService::set()`.

*Deferred because*: introducing static analysis to a codebase of this size produces a baseline that wants its own review pass, which is a phase of work rather than an aside.

*Closed by*: adding Larastan, agreeing a level, and either fixing or explicitly baselining what it reports.

### 5. `helpers.php` is loaded by the service provider

The Settings module loads its `setting()` helper with `require_once` inside `register()`, rather than through Composer's `autoload.files`. The helper is therefore unavailable to anything that boots earlier.

*Deferred because*: the alternative couples the root `composer.json` to a specific module, which trades one flaw for another. The current arrangement works and nothing has needed the helper earlier.

*Closed by*: a decision on which coupling is preferable, recorded wherever the module structure is described.

### 6. Timestamp type inconsistency

`users`, `settings`, `mfa_methods`, `languages`, `integration_*` and `notification_*` use `timestampsTz`. Spatie's `permissions` and `roles` tables use `timestamps`, because they came from a published vendor migration.

*Deferred because*: the two tables carry no time-sensitive logic, and changing a vendor migration to fix a cosmetic inconsistency is a poor trade on its own.

*Closed by*: a migration altering both columns, most sensibly bundled with other work touching those tables.

### 7. Application-level authorization for regular users

Spatie RBAC is administrative infrastructure and only `account_type = admin` participates (ADR 0028). Regular users have no authorization system: no user groups, workspace membership, or application permissions. No speculative tables were created for any of it.

*Deferred because*: nothing consumes it yet, and inventing the schema before a consumer exists would fix the shape before the requirements are known.

*Closed by*: a phase with an actual application-authorization requirement. It must stay conceptually separate from admin RBAC.

### 8. Integration capabilities with no consumer

The Integration module implements SMS only. Email, WhatsApp, storage and AI were named in ADR 0017 but not built.

*Deferred because*: each would have been a seam with nothing on the other side of it. The manager pattern makes adding one a driver method plus a table row.

*Closed by*: the phase that first needs each. WhatsApp OTP as a multi-factor method (ADR 0013) and WhatsApp or push notification channels (ADR 0019) are both waiting on this.

### 9. Container log file permissions

`storage/logs/laravel.log` is not writable inside the backend container under the Windows bind mount. An application error becomes a wall of Monolog failures, which slowed diagnosis of a genuine bug during Phase 6.

*Deferred because*: it is a local Docker environment issue, not application code, and it does not affect correctness.

*Closed by*: fixing ownership or permissions on the mounted `storage` directory in the container image or compose configuration.

## Consequences

The backlog is reviewable and survives the conversations that produced it. Each item can be scheduled on its merits rather than resurfacing as a fresh discovery in a later review.

The risk this record carries is the ordinary one for any backlog: that listing an item comes to feel like addressing it. Items 3 and 4 are the ones to watch, since both are security-adjacent — unlimited public endpoints and absent static analysis are the two entries here most likely to cost something real if they stay deferred indefinitely.
