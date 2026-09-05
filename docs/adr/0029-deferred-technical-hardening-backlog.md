# ADR 0029: Deferred Technical Hardening Backlog

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-04 — CI added as item 2 after Phase 9; item 2 closed by Phase 11, item 5 closed at level 5 in its follow-up
* **Revised**: 2026-09-04 — foundation gap audit items added as a second section, separating decision from implementation
* **Revised**: 2026-09-05 — items 11 and 13 closed by Phase 13, item 12 partially; item 19 recorded from the stranded Phase 12 branch; items 20 and 21 added

## Context

Across Phases 4 to 9 a number of issues were found, judged real, and deliberately not fixed in the phase that found them — because each belonged to a different concern than the one under review, and widening a phase to absorb them would have made it harder to gate rather than safer.

That reasoning holds only while the deferrals are remembered. Until now they existed solely in review conversation, which means they were one context loss away from becoming things nobody had decided about at all. This record makes the backlog durable: an item here has been examined, judged worth doing, and consciously scheduled later — it is not something that was missed.

Nothing here is a blocker for the phase that deferred it. Each entry states what the issue is, why it was deferred, and what would close it.

## Decision

Track the following as deferred hardening. An item leaves this list by being implemented, or by being explicitly reclassified as won't-do with a reason.

*Part One below is the original backlog: issues found during a phase and consciously postponed. Part Two, added 2026-09-04, holds items whose architectural decision is recorded in an ADR and whose implementation alone is outstanding.*

## Part One — deferred hardening

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

## Part Two — decided, implementation pending

Added 2026-09-04, after the foundation gap audit.

Everything in Part One was found during a phase, judged real, and consciously left for later. The items here are different in kind and the distinction matters enough to keep them apart: for each of these the **architectural decision has been made and recorded in an ADR**, and only the implementation is outstanding.

This section exists because of a failure mode the audit exposed. Before it, none of these appeared anywhere: not in this backlog, not in an ADR, not in a phase brief. They were not deferred — nobody had decided anything about them, which is how a foundation ends up shipping `users.update` to an administrator and a `Content-Language: ar` response full of English. "Deferred" is not a substitute for deciding, and an entry here is only valid once the decision above it exists.

### 11. Localization is infrastructure with no consumer — CLOSED (Phase 13)

*Decision*: ADR 0015, extended 2026-09-04. *Implementation*: **complete** — Phase 13, PRs #14 and #15.

`__()`, `trans()` and `Lang::` appear zero times in `app/`. The three keys in `lang/en.json` and `lang/ar.json` are referenced by nothing. `lang/en/` and `lang/ar/` do not exist, so validation messages are the framework English defaults. A request carrying `X-Locale: ar` receives `Content-Language: ar` and an English body — verified live during the audit.

*Closed by*: localization applied at the two choke points ADR 0015 names — the `ApiResponse` trait and the exception handlers in `bootstrap/app.php` — plus published validation catalogues per locale, and translated custom FormRequest messages. Not by translating 74 call sites individually.

### 12. Display labels do not exist — PARTIALLY CLOSED (Phase 13)

*Decision*: ADR 0030, with the RBAC application in ADR 0014. *Implementation*: **partial** — enum labels complete in Phase 13 (PRs #14 and #16); permission and role labels outstanding.

Fifteen enums, none with a display method. Raw backed values reach clients: `not_scanned`, `sms_otp`, `security.alert`, `admin`. Permissions and roles reach clients as `users.update` and `super_admin`. `RoleRequest` requires an administrator to type the technical identifier by hand and offers no field for a human name.

*Closed by*: enum and permission labels in `lang/{locale}.json` keyed by identifier; a `role_translations` table; role identifiers generated from the label and immutable thereafter; the paired payload shape of ADR 0031.

*Remaining after Phase 13*: the enum third is done — eleven enums carry a display method, their labels are in both catalogues, and the payload shape ADR 0031 fixes is implemented. Permission labels do not exist (`permission.*` appears zero times in `lang/en.json`), there is no `role_translations` table, and `RoleRequest` still requires the identifier to be typed by hand with no field for a human name.

### 13. API presentation has drifted into two styles — CLOSED (Phase 13)

*Decision*: ADR 0031. *Implementation*: **complete** — Phase 13 Scope D, PR #16.

One API Resource, from Phase 3, and six private `present()` methods written after it. No record ever chose between them, and there is no shared place to add the labels item 12 requires.

*Closed by*: converting the six controllers to Resources, which is also a prerequisite for ADR 0010 — Scramble infers a contract from Resources and infers nothing useful from a hand-built array.

### 14. Settings cannot express a per-locale value

*Decision*: ADR 0018, extended 2026-09-04. *Implementation*: pending.

A setting holds one value. `site_name`, `site_description`, `maintenance_message`, `cookie_message`, `footer_text` and `footer_copyright` are content a visitor reads and need a value per active language. The `description` column holds a single-language English sentence that the admin API returns as if it were a display label.

*Closed by*: an `is_localized` flag with a `setting_translations` table resolving through the ADR 0015 fallback chain; labels and help text moved to language files; the classification of every setting as technical, localized content, or secret.

### 15. Branding assets have no home

*Decision*: ADR 0018, extended 2026-09-04. *Implementation*: pending.

Logos, favicons, application icons, default social images and the watermark image are all platform configuration that points at a file. Nothing supports it today.

*Closed by*: a media-typed setting whose value is a `MediaFile` id, validated as an existing media record. Binary data never enters the settings table.

### 16. Mail configuration is undecided in code

*Decision*: ADR 0018, extended 2026-09-04 — SMTP is platform configuration in Settings, not a provider behind ADR 0017; an API-driven transactional sender would be the reverse. *Implementation*: pending.

*Closed by*: a `mail` settings group with the password as a secret setting, and the deferred capability to verify a configuration and send a test message.

### 17. Media variants and watermarking are contracts without drivers

*Decision*: ADR 0024, extended 2026-09-04. *Implementation*: deferred on an environment dependency.

The processing pipeline exists; no processor can run. The container image has no gd, imagick or ffmpeg, re-verified on 2026-09-04.

*Closed by*: adding the image extensions to the container image, then implementing named variants and watermarking against the existing contracts. The original is never modified and never watermarked.

### 18. SEO has no contracts

*Decision*: ADR 0032. *Implementation*: deliberately unphased.

*Closed by*: implementing the contracts when a consumer exists whose requirements can test them. Building them against no consumer is the speculative surface ADR 0024 has already recorded twice, and ADR 0033 forbids.

### 19. Scramble emits a keyword OpenAPI 3.1 does not allow — upstream blocker

*Decision*: ADR 0010, unchanged. *Implementation*: **blocked upstream**, not deferred by project choice.

This entry is a different kind from everything above it. Items 1 to 18 are work this project chose to postpone and can start whenever it decides to. This one cannot be started at all: the defect is in a dependency, and no amount of work here fixes it.

Scramble `v0.13.42` generates a valid-looking 3.1 document that fails validation. For a fixed-length array literal it emits a tuple using `prefixItems`, which is correct, and alongside it `additionalItems: false`, which was removed in JSON Schema draft 2020-12 — the dialect OpenAPI 3.1 uses. Redocly reports `Property additionalItems is not expected here` at five nodes. Spectral flags the same nodes for a different reason, its `array-items` rule not accounting for `prefixItems`; that part is a limitation of the rule rather than a second defect.

The keyword is set in `TypeTransformer.php:235` via `setAdditionalItems(false)` on the tuple branch, and serialised by `ArrayType.php:101`. It appears zero times in `app/`. The valid 3.1 form is `items: false` in its place, or nothing at all, since `minItems` and `maxItems` already constrain the length — so the upstream change is expected to be small.

It cannot be avoided from application code. Annotating the source DTO with a precise array shape produces a **byte-identical** document, because the inference comes from the array literal rather than from the docblock. That was tested, not assumed.

Three constraints hold while this is open, recorded here so that none of them is quietly decided later by someone reading "blocked" as "deferred":

* **The application is not changed to work around it.** Editing a DTO or a controller so a third-party serialiser emits valid output would hide a defect in that serialiser behind a change to our own contract.
* **No linter rule is disabled and no ignore file is added.** A suppressed rule is a validator that has stopped validating, which this project has already learned to distrust twice — an architecture rule guarding a namespace nothing imported, and a secret scan reporting clean because it had stopped looking.
* **`vendor/` is not patched.** A local patch would make the document valid on one machine and leave the next `composer install` producing an invalid one.

*Closed by*: an upstream release that emits `items`, or nothing, in place of `additionalItems` for 3.1 documents. Until then the contract can be generated and read but **cannot be validated**, so the Spectral gate ADR 0021 lists for Phase 12 stays off, and nothing is built on the assumption that the document validates.

The 83 style warnings the same run reports are unrelated and are not part of this blocker: absent operation descriptions and undeclared tags, which is metadata Phase 12 has not written yet.

*Recorded 2026-09-05.* Scramble was installed and this defect found on a Phase 12 branch that was never merged; the finding existed only in one working copy until now, which is the failure mode this record was created to prevent. Scramble itself is **not** installed on `main`.

### 20. The admin media index has no test for its paginated shape

*Decision*: none required. *Implementation*: pending.

Phase 13 converted `MediaAdminController::index` to a Resource inside a paginator. The envelope, the `meta.pagination` block and the row shape were verified by hand twice during that phase and match what the controller returned before, but the only automated assertion on that endpoint is that the request succeeds. A change to the paginated envelope would pass CI.

*Deferred because*: it was found during a phase whose commits were not permitted to modify tests, and adding it later is cheaper than widening that phase.

*Closed by*: a test asserting the top-level keys, the five `meta.pagination` keys, and the admin row field list against the endpoint rather than against the Resource in isolation.

### 21. The development and test environments share Redis

*Decision*: none required. *Implementation*: pending.

Both use the same Redis database, so a test run leaves entries behind in the cache the development application reads. Observed repeatedly during Phase 13: after a suite run, `localization:languages:active` holds an empty array while the table holds two active languages, and a manual probe of `X-Locale: ar` resolves to `en` until the cache is cleared or its 24-hour TTL expires.

This is an environment concern rather than an application one. The automated tests are unaffected — they flush the cache per test — and the poisoning is invisible until someone probes the running application by hand and is misled by it, which happened more than once.

*Deferred because*: it belongs to test and container configuration, and Phase 13 was scoped to localization and presentation.

*Closed by*: a separate Redis database index for the test environment, so a suite run cannot reach the development cache.

## Consequences

The backlog is reviewable and survives the conversations that produced it. Each item can be scheduled on its merits rather than resurfacing as a fresh discovery in a later review.

The risk this record carries is the ordinary one for any backlog: that listing an item comes to feel like addressing it. Item 2 closed in Phase 11, which is the shape this list is meant to have: an entry leaves by being built, not by being forgotten. Item 5 closed at level 5 in the phase after Phase 11, which leaves item 4 — unlimited public endpoints — as the entry in Part One most likely to cost something real if it stays deferred indefinitely.

Part Two carries a different risk. Its items are not hardening; they are capabilities the platform presents as working. Item 11 is the sharpest: the API advertises a language in a response header it does not honour in the body, so this is a contract being broken rather than a feature being awaited. Items 11, 12 and 13 are also mutually blocking in one direction — labels need a presentation layer to appear in, and both need localization to resolve against — which makes their order a sequencing decision rather than a free choice.

That sequencing was settled by Phase 13, which took them in the only order that works: localization first, then the presentation layer, then the labels that needed both. Items 11 and 13 closed and item 12 lost its enum third.

Item 19 is different again: it is the only entry on this list that no decision of ours can close, which is why it says blocked rather than deferred. Items 20 and 21 are ordinary deferrals of the Part One kind, recorded here rather than in that section only because they were found after it was written.
