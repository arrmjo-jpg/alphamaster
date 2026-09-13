# ADR 0050: Social Login, for User Accounts Only

* **Status**: Accepted
* **Date**: 2026-09-12
* **Accepted**: 2026-09-13
* **Revised**: 2026-09-12 — the promotion rule counts currently linked identities only
* **Revised**: 2026-09-12 — provider verification is not local email verification; promotion refusal, row locking and provider enablement stated against ADR 0012, ADR 0038 and `AccountTypeManager`
* **Revised**: 2026-09-13 — on acceptance, §9 narrowed to the security exceptions ADR 0037 grants; authentication events and ordinary refusals stay out of the trail
* **Amends**: ADR 0037 (scope — see §9)
* **Relates to**: ADR 0012, ADR 0013, ADR 0017, ADR 0018, ADR 0035, ADR 0038, ADR 0039, ADR 0042, ADR 0046

## Context

The platform has no social login. A read-only audit on 2026-09-12 found no OAuth library in `composer.json`, no social routes, controllers or services, no social settings, no identity table, and nothing in the Admin. It also found no self-registration: `auth.registration_enabled` is declared and nothing reads it (`docs/m3-decisions.md`).

What does exist, and what this record has to fit:

* **One identity, two standings.** `AccountType` is `admin` or `user`, constrained in the database. `account_type` is excluded from mass assignment; `AccountTypeManager::promote()` / `demote()` is the only sanctioned route across the boundary (ADR 0012).
* **An administrative perimeter built on token abilities.** An administrator's token carries `admin:access`, a user's `user:access`, never both. Every access token is minted by `AuthService::issueToken()`, which already refuses to mint `admin:access` for an unverified administrator (ADR 0012, fifth stage). MFA is mandatory for administrators (ADR 0013).
* **A verified address means control proved to this platform.** `email_verified_at` is written by one path in ordinary operation: following the signed link `EmailVerificationService` mails. The code draws the line explicitly — an address an administrator typed is not marked verified, and changing an address clears its verification, because that is *"the difference between a verified identity and an asserted one (ADR 0012)"*.
* **The audit trail records administrative intent, and draws two lines.** ADR 0037's 2026-09-10 extension keeps authentication events — sign-in, a failed attempt, an MFA challenge — out of the trail, assigning them to an authentication log with its own retention; and it records nothing for a refused operation. No authentication log exists, and no sign-in, password or otherwise, is recorded anywhere today.
* **No stateful sessions.** ADR 0042 forbids Sanctum's SPA mode because a `TransientToken` answers `true` to every ability.
* **Configuration is versioned and optimistic.** ADR 0038 gives every settings group and provider configuration an `If-Match` version, rejects pessimistic locks held across an administrator's editing session, and separates a provider being stored from being enabled and effective.
* **Vendor credentials live on Integration provider rows, not in settings.** The captcha is the precedent: its switch, site key and version are `auth` settings; its secret key is "deliberately not a setting" and sits encrypted on the provider row (ADR 0017, ADR 0039).
* **`users.password` is `NOT NULL`, and there is no password-reset flow.** `password_reset_tokens` exists as a framework default; no endpoint uses it.
* **No public frontend.** The repository holds `admin/` and `adminapp/` only. No public origin is known.

Social login is the first unauthenticated path that can *create* an account, and the first whose proof of identity comes from a third party. Both properties make it a candidate for privilege escalation unless the boundary is decided before any of it is written.

## Decision

> **Social Login is available only to account type `user`.**
>
> **An account with one or more currently linked Social Identities cannot be promoted to `admin`.**
>
> **Promotion must be rejected while a Social Identity is linked.**
>
> **Unlinking a Social Identity does not delete the historical record, but after all Social Identities are unlinked, the account is no longer blocked by this Social Login promotion rule.**
>
> **Social provider verification is not local email verification. `email_verified_at` is never written when an account is created or linked through a social provider, and a provider's assertion is never a substitute for the signed verification link of ADR 0012.**

The rest of this record makes those sentences true everywhere they can be tested, including the paths nobody intends.

### 1. Who may use social login

Only an account whose `account_type` is `user`. An administrator cannot sign in, register, or link an identity through a social provider, by any route, under any configuration. There is no setting that changes this.

### 2. Administrative authentication stays separate

Administrators sign in with a password, a verified address and mandatory MFA (ADR 0012, ADR 0013), unchanged. Every social route lives under `/api/v1/auth/social/*`, never under `/api/v1/admin/*`. No social flow can produce `admin:access`. The Admin console and the mobile admin app offer no social sign-in.

### 3. The callback decides in a fixed order

Sign-in intent, after the state, PKCE binding and provider response have been verified (§8). "Linked" means a `social_identities` row with `unlinked_at IS NULL`. "Provider-verified" means the provider's own assertion (§4) — never the account's `email_verified_at`.

| # | Condition | Outcome |
|---|---|---|
| 1 | A **linked** identity `(provider, provider_subject)` exists and its account is `admin` | **Refuse.** Unreachable by construction (§6); checked anyway. |
| 2 | A linked identity exists and its account is inactive | **Refuse**, as sign-in does for a suspended account. |
| 3 | A linked identity exists and its account is an active `user` | **Sign in** as that account. MFA is honoured exactly as at password sign-in (§7). |
| 4 | The `(provider, provider_subject)` exists **only as an unlinked** row | **Refuse.** The subject stays reserved to the account it was unlinked from: it signs nobody in, creates no account, and can be re-linked only by that account (§5). |
| 5 | No identity; the provider's email, normalised, matches an `admin` account — **provider-verified or not** | **Refuse.** No sign-in, no link, no account created. |
| 6 | No identity; the provider does not assert the email as verified | **Refuse.** Nothing is matched, linked or created on an address the provider does not vouch for. |
| 7 | No identity; provider-verified email matches an existing `user` | **Refuse with `SOCIAL_IDENTITY_NOT_LINKED`.** No automatic linking (§5). The user signs in by an existing method and links explicitly. |
| 8 | No identity; no account has the email; `auth.registration_enabled` is off | **Refuse with `REGISTRATION_CLOSED`.** |
| 9 | No identity; no account has the email; registration is on | **Create a `user`**, with `email_verified_at` left `NULL` (§4), link the identity, sign in. |

Rows 1, 2, 4, 5 and 6 return one indistinguishable refusal (`SOCIAL_SIGN_IN_REFUSED`). Where the refusal involves an administrator — rows 1 and 5 — the audit trail records which it was (§9); the response never does.

Row 5 runs before rows 6 and 7 on purpose: an administrator's address is never matched against, whatever the provider claims about it.

### 4. Provider verification is not local verification

**Two different facts, kept apart:**

| | Provider verification | Local email verification |
|---|---|---|
| What it is | A third party's assertion (`email_verified: true` or its equivalent) that the address was verified *by that provider* | Control of the address proved *to this platform*, by following the signed link of ADR 0012 |
| Where it lives | The callback request only; never persisted | `users.email_verified_at` |
| What it decides | Only the social flow's own decisions in §3: whether an address may be matched against, and whether an account may be created on it | Everything ADR 0012 gates, including the fifth stage of the administrative perimeter |
| Who writes it | Nobody — it is read and discarded | `EmailVerificationService`, as today, and nothing this record adds |

**Consequently:**

* A socially registered account is created with `account_type = 'user'` — the database default, which mass assignment cannot override — and **`email_verified_at = NULL`**.
* Linking an identity to an existing account never writes `email_verified_at`, in either direction: it neither sets it nor clears it.
* The account is reported as unverified until its holder follows the signed link, exactly as a password-registered account would be. ADR 0012 does not stop a regular user for being unverified, so this costs a user nothing at sign-in.
* An account that later becomes an administrator (§6) meets ADR 0012's fifth stage the only way any account does: an `email:verify` credential, the signed link, then MFA enrolment. No provider assertion shortens that path.

ADR 0012 is not amended by this record. Treating a provider's assertion as local verification would make `email_verified_at` mean "some provider said so", which is the "asserted rather than verified" identity the platform already refuses when an administrator types an address.

A provider that cannot assert verification cannot register accounts or email-match (§10).

### 5. Linking is explicit and authenticated, and `user`-only

An identity is attached to an existing account only when the holder is signed in with a `user:access` token and completes a link flow for that account. It is refused when:

* the account is `admin` (unreachable: an administrator holds no `user:access` token; checked anyway, and enforced in the database, §6);
* the `(provider, provider_subject)` belongs to another account, **including as an unlinked row** — an identity never moves between accounts. Re-linking a subject to the account it was unlinked from clears `unlinked_at` on the existing row;
* the account already has a linked identity for that provider — one linked identity per provider per account.

**There is no automatic linking by email, in any case.** Matching a provider's verified email to an existing account and linking silently is the account pre-hijacking pattern: whoever controls the address at the provider — or registered it here first — gains the other half of the account. An explicit link from inside the account proves control of both.

**Unlinking** sets `unlinked_at` and never deletes the row. It is refused when it would leave the account with no sign-in method: no password and no other linked identity.

### 6. Promotion is refused while any social identity is linked

`AccountTypeManager::promote()` refuses when the account has **one or more linked** `social_identities` rows (`unlinked_at IS NULL`). It answers `PROMOTION_REFUSED_SOCIAL_IDENTITY`, records the refusal, and changes nothing: the account stays `user`, its identities stay linked, no token is revoked.

**No identity is unlinked or removed to make promotion possible.** Unlinking is the account holder's act (§5), never a side effect of an administrator's.

**Once every identity is unlinked, this rule no longer applies.** The unlinked rows remain as history and do not block promotion; the account is then subject to the ordinary promotion rules. The reasoning: while an identity is linked, a social provider is a live way to sign in to the account, and promoting it would make that provider an administrative sign-in method. After the last unlink, no provider can sign in to the account at all (§3 row 4), so promotion converts nothing.

That unlinked account necessarily has a password: §5 refuses the unlink that would leave it without one, and §12 requires every administrator to have one. Promotion then takes it through ADR 0012's verification stage (§4) and mandatory MFA enrolment (ADR 0013) like any other account.

**Enforced in three places, deliberately:**

1. **The service** — `promote()` checks inside a transaction that locks the user row, and the link flow takes the same lock, so a link cannot interleave with a promotion.
2. **Issuance** — the social flow mints its token through a dedicated method that raises if the account is `admin`, the way `issueToken()` raises for an unverified administrator. Unreachable in ordinary operation; a loud failure if a path exists that nobody intended.
3. **The database** — two triggers, on PostgreSQL and SQLite, following the precedent of `chk_users_account_type_allowed`:
   * an update setting `users.account_type = 'admin'` fails if any **linked** `social_identities` row references the user;
   * an insert, or an update, that leaves a `social_identities` row **linked** fails if the referenced user is `admin`.

The triggers exist because a service check alone is not the whole invariant: a console command, a seeder, a restore or a raw statement reaches the table without the service, and a link racing a promotion can pass two independent checks. ADR 0038 names the same residual risk — a write that bypasses the API — as the reason database invariants stay in the database.

**The row lock is not the pessimistic locking ADR 0038 rejects.** ADR 0038 rejects a lock *held across an administrator's editing session* on configuration. The lock here is taken and released inside one short transaction to make a single state transition atomic — no human edits while it is held — and it governs accounts, not configuration. ADR 0038's optimistic `If-Match` model is unaffected and still governs every configuration write this record introduces (§10).

**How the refusal is shaped, stated because the current code makes the obvious shape wrong:**

* **A refusal is an exception, never a silent return.** `promote()` already returns the account unchanged when it is already an administrator, so returning it unchanged for a refusal would be indistinguishable from "nothing to do". The refusal raises a domain exception that the controller maps to `409 PROMOTION_REFUSED_SOCIAL_IDENTITY`. `AccountTypeManagerContract` currently promises only that promotion succeeds and revokes tokens; its documentation is updated to name the refusal when this is implemented.
* **The refusal is recorded outside the transaction it aborts.** `UserAdminController::promote()` wraps the manager in a transaction so that a promotion and its audit record commit together. A refusal rolls that transaction back, and an `account.promotion_refused` record written inside it would roll back with it. The refusal record is written after the aborted transaction, in its own (§9).
* **The check reads fresh state, and precedes every effect.** The `User` handed to `promote()` was loaded before the transaction, and the controller counts tokens before calling it. Inside the transaction the user row is re-read under `lockForUpdate`, the linked identities are counted, and only then does anything change. A refused promotion revokes no token.

### 7. Token issuance

A social sign-in produces a `user:access` token and nothing else, via `AuthService` (ADR 0012), with a token name that says it came from social sign-in. It never produces `admin:access`, `mfa:enrol` or `email:verify`.

If the user has MFA enabled, the social callback answers exactly as password sign-in does: a challenge credential, not an access token. Social sign-in does not bypass a second factor the user chose.

The response has the shape of `POST /auth/login`, and the transport that applies to login applies to it. This record decides no new transport.

### 8. Protocol security

* **State** — 256 bits from a CSPRNG, stored server-side in the platform cache (ADR 0035) under a hash of its value, for ten minutes, and consumed atomically: read-and-delete, so it is single-use. It is bound to the provider, the intent (`sign_in` or `link`), the exact redirect URI, the PKCE challenge, the OIDC nonce and — for `link` — the user id. A state that is unknown, expired, already used, or presented with any bound value differing is one failure: `SOCIAL_STATE_INVALID`. A cache outage fails closed.
* **PKCE, client-generated** — the client creates the `code_verifier`, sends only the S256 `code_challenge` to `authorize`, and presents the verifier at the callback. The server checks `S256(verifier) = challenge` **before** redeeming the code, and passes the verifier to the provider where the provider supports PKCE. This binds the callback to the client that started the flow — the defence against login CSRF, where a victim's client is made to complete an attacker's authorisation — and it works for a browser and a native app alike, without a cookie. Where a provider does not support PKCE, the server-side check still provides the binding.
* **CSRF** — the social endpoints accept no ambient credential for their security decision: sign-in is bound by state and verifier, and link additionally requires a `user:access` token. Nothing is decided by a cookie the browser attaches on its own.
* **OIDC** — where the provider is OpenID Connect, the ID token is validated: signature against the provider's published keys, `iss`, `aud` equal to the client id, `exp`, `iat` within skew, and `nonce` equal to the stored one. Claims come from the validated ID token in preference to a userinfo call.
* **Provider-verified email** — honoured only as an explicit assertion from the provider (`email_verified: true` or the provider's equivalent). Absence of the assertion is "not provider-verified". It decides the flow in §3 and nothing else: **it is never written to `email_verified_at`** (§4). An email is normalised by trimming and lower-casing only; no provider-specific rewriting.
* **Replay** — state is single-use (above); the authorization code is single-use at the provider; the ID token's `nonce` is bound to that state.
* **Account enumeration** — `authorize` takes no email and reveals nothing. The callback distinguishes `SOCIAL_IDENTITY_NOT_LINKED` and `REGISTRATION_CLOSED` only after the caller has proved control of that address at the provider; every refusal involving an administrator, an inactive account, an unlinked identity or an address the provider does not vouch for is the same `SOCIAL_SIGN_IN_REFUSED`. Unknown, disabled and not-yet-effective providers are the same `SOCIAL_PROVIDER_UNAVAILABLE`.
* **Rate limiting** — the social routes join the `auth` class ceiling of the login route, and refusals are metered per ADR 0046.
* **Identity key** — `provider_subject` is the provider's immutable subject (`sub` for OIDC, the immutable account id otherwise). **Never the email**, never a username: both are mutable, and addresses are reassigned.
* **Provider errors** — redacted before they reach a response, log or audit record, through the same boundary as other vendor errors; the caller receives `SOCIAL_PROVIDER_ERROR`.
* **Provider tokens are never stored.** The access token, refresh token and ID token are used to establish identity within the callback request and discarded. They are not written to a table, a log, a cache entry or an audit record.

### 9. Audit

ADR 0037 draws two lines this record does not overturn: **authentication events stay out of the trail**, and **a refused operation writes nothing**. On acceptance, ADR 0037 gains the narrow exceptions below — each argued in its 2026-09-13 extension — and nothing else.

**Recorded in the trail**, with no email address, no provider subject, and no code, state, verifier, nonce, ID token, access token, refresh token or client secret, in any form:

| Action | When | Context | Why it belongs in the trail |
|---|---|---|---|
| `account.created` | Social registration | `source: social`, `provider` | Already in ADR 0037's scope: an account exists that did not. Not an exception. |
| `account.social_linked` | An identity linked or re-linked, at registration or explicitly | `provider`, `identity_id`, `relinked` | A change to who may sign in to the account |
| `account.social_unlinked` | An identity unlinked | `provider`, `identity_id`, `linked_remaining` (count) | The same; `linked_remaining: 0` marks the moment the promotion rule stops applying |
| `auth.social_refused` | §3 row 1 or row 5 **only** | `provider`, `reason`: `admin_account` or `admin_email_match` | A social provider used to reach an administrative account |
| `account.promotion_refused` | `promote()` refused under §6 | `reason: social_identity`, `linked_identities` (count) | A blocked crossing of the administrative boundary |

For `auth.social_refused` the subject is the administrative account that was matched. For the others it is the account acted on.

**Not recorded in the trail**, because they are authentication events or ordinary refused requests and ADR 0037 keeps both out:

* a social sign-in, whether it issued a token or an MFA challenge;
* refusals for an inactive account, an unlinked identity, an address the provider does not vouch for, closed registration, an identity in use, a provider already linked, and the last sign-in method;
* every state and PKCE failure.

They belong to the authentication log ADR 0037 names. **That log does not exist**; when it is designed it covers password sign-in and social sign-in alike, so neither method is recorded where the other is not. Until then the rate limiter still meters refusals (ADR 0046) and the request log still carries every request.

**Where each record is written:**

* `account.created`, `account.social_linked` and `account.social_unlinked` commit with the change they describe, inside its transaction, as ADR 0037 requires.
* `account.promotion_refused` is written in its own transaction after the promotion's has rolled back (§6) — the one place the "inside the transaction" rule gives way, because the operation it describes did not happen.
* `auth.social_refused` describes a request that changed nothing, and is written in its own transaction. As everywhere in ADR 0037, a failure to write it is not swallowed; the request stays refused either way.

### 10. Providers: storage in Integration, placement in Authentication

Following the captcha precedent:

* **Integration** (ADR 0017) owns each provider: a `social_login` capability; one provider row per configured provider with its driver key, its non-secret settings (client id) and its **client secret encrypted on the row** (ADR 0039) — never a setting.
* **ADR 0038 governs it unchanged.** Provider configuration carries its `If-Match` version, and a provider moves through `stored → valid → configured → enabled → effective` like any other. **The `social_login` capability declares its required configuration as the driver key, a client id and a client secret**; a provider missing any of them cannot be enabled, whatever order they were saved in. A capability verifier is optional, as ADR 0038 allows, and its absence is reported rather than implied to have passed.
* **Only effective providers are offered.** `GET /providers`, `authorize` and `callback` consider a provider available only when it is enabled *and* its required configuration is present — never merely enabled. Anything else is `SOCIAL_PROVIDER_UNAVAILABLE`.
* **There is no failover.** A Google identity is not an Apple identity; providers are listed side by side, each enabled independently, which is the same exception ADR 0044 makes for AI.
* **Authentication settings** own the switches: `auth.social_login_enabled` (public, off by default) and `auth.social_redirect_uris` (the exact client redirect URIs an operator allows; empty by default, which makes the feature unusable until configured). Social registration is governed by the existing `auth.registration_enabled`. Both are settings, so both are written under their group's `If-Match` version (ADR 0038). The switch on with no effective provider offers an empty list; it refuses nothing that would otherwise have been allowed.
* **The Admin surfaces provider configuration within the Authentication area**, so an operator configures social login where they configure sign-in. The storage is Integration's; the placement is Authentication's.
* **No provider is chosen by this record.** A provider is eligible to register or email-match only if it asserts email verification; a provider that cannot may still sign in an identity already linked by §5.
* A library may implement the protocol only in stateless mode: state, PKCE and nonce storage belong to the platform (§8), not to a framework session (ADR 0042).

### 11. The client contract assumes no frontend domain

The API performs the code exchange; the browser or app only carries the redirect. Neither a public website nor its domain is assumed.

1. The client generates a PKCE verifier and calls `authorize` with the challenge and a redirect URI from `auth.social_redirect_uris`.
2. It sends the user to the returned `authorization_url`.
3. The provider redirects to the client's redirect URI with `code` and `state`.
4. The client posts `code`, `state` and `code_verifier` to the callback.
5. The platform answers as sign-in does.

A web client uses an `https` URI; a native app uses a claimed app link or custom scheme registered as an exact entry. The same contract serves both, and a future public frontend is one more allowed URI, not a change here.

### 12. Social-only accounts and recovery

* **`users.password` becomes nullable. `NULL` means the account has no password sign-in.** Password sign-in against such an account fails exactly as a wrong password does, with the same response and comparable timing.
* **An administrator must always have a password**: a `CHECK` enforces `account_type <> 'admin' OR password IS NOT NULL`.
* **Recovery.** A social-only user who loses access to their provider recovers through their email address, **by a flow that proves control of that address at the time of recovery** — a signed, expiring link to the address, which then allows a password to be set. It does not rely on `email_verified_at`, which for a socially registered account may still be `NULL` (§4), and it does not rely on the provider's past assertion. **No such flow exists today, and social registration must not be enabled in production until it does** (implementation plan, phase 7). A signed-in social-only user may set a password, and may link a second provider. There is no administrator-assisted recovery: an operator attaching an identity or setting a password on someone else's behalf is an account-takeover path.

## API contract

All under `/api/v1/auth/social`. Responses use the platform envelope; error codes are the `error.code` value.

| Method & path | Auth | Request | Success |
|---|---|---|---|
| `GET /providers` | none | — | `200` `[{ key, label }]` — **effective** providers only (§10); `[]` when the switch is off |
| `POST /{provider}/authorize` | none | `{ redirect_uri, code_challenge, code_challenge_method: "S256" }` | `200` `{ authorization_url, expires_at }` |
| `POST /{provider}/callback` | none | `{ code, state, code_verifier }` | `200` as `POST /auth/login` (token, or MFA challenge); `201` with the same body when an account was created |
| `GET /identities` | `user:access` | — | `200` `[{ id, provider, linked_at, last_used_at }]` — linked only |
| `POST /{provider}/link/authorize` | `user:access` | as `authorize` | `200` as `authorize` |
| `POST /{provider}/link` | `user:access` | `{ code, state, code_verifier }` | `201` `{ id, provider, linked_at }` |
| `DELETE /identities/{identity}` | `user:access` | — | `204` |

An account created through the callback is returned with `email_verified: false` and `email_verified_at: null` (§4).

Administrative additions, under the existing admin perimeter: `POST /api/v1/admin/users/{user}/promote` may answer `409 PROMOTION_REFUSED_SOCIAL_IDENTITY`; the admin user resource gains `has_linked_social_identity: bool`, true only while an identity is linked. Provider configuration uses the existing Integration admin endpoints, with their `If-Match` versions.

| Code | HTTP | Meaning |
|---|---|---|
| `SOCIAL_PROVIDER_UNAVAILABLE` | 404 | Unknown provider, disabled or not-yet-effective provider, or the switch is off |
| `INVALID_REDIRECT_URI` | 422 | Not an exact entry of `auth.social_redirect_uris` |
| `VALIDATION_ERROR` | 422 | Malformed request body, as elsewhere |
| `SOCIAL_STATE_INVALID` | 422 | State unknown, expired, used, or bound values/verifier mismatch |
| `SOCIAL_PROVIDER_ERROR` | 502 | The provider exchange or token validation failed; detail redacted |
| `SOCIAL_SIGN_IN_REFUSED` | 403 | Administrator account or address, inactive account, unlinked identity, address not provider-verified |
| `SOCIAL_IDENTITY_NOT_LINKED` | 409 | Provider-verified email belongs to an existing user; sign in and link |
| `REGISTRATION_CLOSED` | 403 | No account, registration disabled |
| `SOCIAL_IDENTITY_IN_USE` | 409 | Link: identity belongs to another account, linked or unlinked |
| `SOCIAL_PROVIDER_ALREADY_LINKED` | 409 | Link: account already has a linked identity for this provider |
| `LAST_SIGN_IN_METHOD` | 409 | Unlink would leave no way to sign in |
| `PROMOTION_REFUSED_SOCIAL_IDENTITY` | 409 | Admin promote of an account with a linked social identity |
| `TOO_MANY_ATTEMPTS` | 429 | Existing throttle, with `Retry-After` |

## Database model

**`users`** — `password` becomes nullable; `CHECK (account_type <> 'admin' OR password IS NOT NULL)`. `email_verified_at` is unchanged in shape and in who writes it (§4).

**`social_identities`** — owned by the User module (User may not depend on Auth, and `AccountTypeManager` must read it):

| Column | Type | Constraint |
|---|---|---|
| `id` | ULID | primary key |
| `user_id` | ULID | not null, foreign key `users.id`, `ON DELETE CASCADE`, indexed |
| `provider` | varchar(50) | not null; the driver key, not the provider row id, so identities survive a provider row being recreated |
| `provider_subject` | varchar(255) | not null |
| `email` | varchar(255) | nullable; a snapshot for display, never used to look anything up |
| `linked_at` | timestamptz | not null; set again on re-link |
| `last_used_at` | timestamptz | nullable |
| `unlinked_at` | timestamptz | nullable; `NULL` means linked. Unlinking sets it and never deletes the row |
| `created_at`, `updated_at` | timestamptz | |

* `UNIQUE (provider, provider_subject)` — across all rows, linked or not, so a subject never moves between accounts.
* Partial `UNIQUE (user_id, provider) WHERE unlinked_at IS NULL` — one linked identity per provider per account.
* Partial index `(user_id) WHERE unlinked_at IS NULL` — the promotion check reads it.
* Trigger: an insert, or an update leaving the row linked, refused when the referenced user is `admin`.
* Trigger on `users`: setting `account_type = 'admin'` refused when any **linked** `social_identities` row references the user. Unlinked rows do not block it.
* No column for provider verification, and none for any provider token or secret. OAuth state is not a table (§8).

## Security rules

**Accept**

1. Linked identity, active `user` → sign in (or MFA challenge).
2. No identity, provider-verified email, no account, registration on → create `user` with `email_verified_at = NULL`, link, sign in.
3. Signed-in `user`, identity unused by any other account, no linked identity for that provider → link, leaving `email_verified_at` untouched.
4. Signed-in `user` re-linking a subject previously unlinked from the same account → re-link the existing row.
5. Signed-in `user`, another sign-in method remains → unlink (row kept, `unlinked_at` set).
6. Promote an account with no **linked** `social_identities` row — including one whose identities are all unlinked → existing promotion rules, then ADR 0012 verification and ADR 0013 enrolment at sign-in.

**Refuse**

1. Linked identity whose account is `admin`.
2. Linked identity whose account is inactive.
3. Sign-in with a subject that exists only as an unlinked row.
4. Provider email matching an `admin` account, provider-verified or not — before any other email rule.
5. Provider email not asserted verified by the provider, when the flow would match or create by email.
6. Provider-verified email matching an existing `user` with no linked identity — no auto-link.
7. No account and registration disabled.
8. Link by an `admin` account, or by any caller without a `user:access` token.
9. Link of an identity that belongs to another account, linked or unlinked.
10. Link of a second identity for a provider the account already has linked.
11. Unlink of the last sign-in method.
12. Promotion of an account with one or more linked `social_identities` rows — no automatic unlink, no removal, no exception while linked; raised, not returned silently; recorded outside the aborted transaction; no token revoked.
13. Any database write making an `admin` account own a linked identity, or an account with a linked identity `admin`.
14. An `admin` account without a password.
15. **Any social flow writing `email_verified_at`, or treating a provider's assertion as satisfying ADR 0012's fifth stage.**
16. State unknown, expired, reused, or presented with a different provider, intent, redirect URI, verifier, nonce or user.
17. A redirect URI that is not an exact allowed entry.
18. An ID token failing signature, issuer, audience, expiry or nonce validation.
19. Unknown, disabled or not-yet-effective provider, or the switch off.
20. Any social flow yielding `admin:access`, `mfa:enrol` or `email:verify` — raises.

## Alternatives rejected

| Alternative | Why rejected |
|---|---|
| Social login for administrators, protected by MFA | Moves proof of an administrator's identity to a third party; a compromised provider account becomes an administrative sign-in method. Contradicts ADR 0012's verified-address stage and the separation this record exists for. |
| Writing `email_verified_at` from the provider's assertion | Changes what local verification means from "control proved to this platform" to "some provider said so" — the asserted-rather-than-verified identity the platform already refuses when an administrator types an address. It would also let a promoted account skip ADR 0012's signed link on the strength of a third party's past claim. |
| Amending ADR 0012 to accept provider assertions | Weakens a stage that exists specifically for administrators, to save a regular user a step they are not required to take. |
| Recording every social sign-in and every refusal in the audit trail | Reverses ADR 0037's reasoned exclusion of authentication events, puts request-frequency writes into a record of administrative intent, makes an audit outage block user sign-in, and records social sign-in while password sign-in is recorded nowhere. |
| Recording only the promotion refusal and linking, and not admin-targeting refusals | Leaves no durable trace when a social provider is used against an administrative address — the event this feature most needs to be able to show. |
| Automatically unlinking identities on promotion | Silently alters the user's identity as a side effect of an administrator's act, and locks out a social-only user. Unlinking is the holder's decision. |
| Permanent ineligibility once any identity was ever linked | Blocks on history rather than on anything live. After the last unlink no provider can sign in to the account, so promotion converts no social identity into an administrative sign-in method; refusing it would protect nothing that still exists. |
| Returning the account unchanged when promotion is refused | Indistinguishable from the existing "already an administrator" return. A refusal must be visible as one. |
| Recording the promotion refusal inside the promotion transaction | The refusal aborts that transaction, and the record would roll back with it. |
| Deleting identity rows on unlink | Loses the record of which subject belonged to which account, and frees a subject to be linked to a different account. |
| Enforcing the promotion rule in the service only | A race between link and promote passes two independent checks, and a console command, seeder, restore or raw statement never reaches the service. |
| Automatic linking by verified email | The account pre-hijacking pattern. Explicit, authenticated linking proves control of both sides. |
| Email as the identity key | Mutable and reassignable; a recycled address would sign someone into another person's account. |
| Storing provider access or refresh tokens | Nothing consumes them. A stored credential with no consumer is only a liability. |
| A separate guard or model for social users | ADR 0012 chose one identity with scoped tokens; a second identity model duplicates the boundary it would have to keep in step. |
| Framework session for OAuth state | ADR 0042 forbids stateful sessions on this API. |
| A redirect-and-cookie callback on the API origin | Assumes a frontend domain that does not exist, and does not serve a native app. |
| Client secrets as settings | Contradicts the captcha precedent and ADR 0017 / ADR 0039: vendor credentials live encrypted on provider rows. |
| Offering providers that are enabled but not fully configured | ADR 0038: a provider never becomes effective in a partially configured state. |
| A random placeholder password for social-only accounts | A credential nobody knows, which makes the account look password-capable and hides that it is not. |
| Administrator-assisted recovery | An operator attaching an identity or setting a password for someone else is an account-takeover path. |

## Consequences

* The promotion rule holds at the service, at issuance and in storage, and is testable against a raw statement as well as through the service.
* **Local email verification keeps exactly one meaning.** No social path writes `email_verified_at`; a socially registered account starts unverified, and ADR 0012 needs no amendment.
* An account that unlinks every identity becomes promotable. It keeps its unlinked rows, it has a password (§5, §12), and its subjects still cannot sign in to it or to any other account (§3 row 4). If it has never followed the signed link, its first administrative sign-in is stopped at ADR 0012's verification stage like any unverified administrator's, and proceeds only through `email:verify`, the signed link and MFA enrolment.
* An administrator may therefore have unlinked identity rows from before promotion. They are history: the triggers stop any of them being re-linked while the account is `admin`.
* `AccountTypeManagerContract` gains a documented refusal when this is implemented. The contract is not changed by this record.
* **The audit trail gains four actions and keeps its two lines.** Social sign-in and ordinary refusals are not recorded, exactly as password sign-in is not, so an audit outage cannot block a user signing in. What the trail can answer is who linked or unlinked an identity, who tried to promote a socially linked account, and when a provider was used against an administrative account.
* **An authentication log remains unbuilt.** Until it exists there is no durable record of any sign-in, social or password — a gap ADR 0037 already names, not one this record opens.
* The first unauthenticated path that creates accounts is bound to `auth.registration_enabled`, which gains its first reader.
* `users.password` becomes nullable. Every place that reads a password is audited for `NULL` before this ships.
* Social registration waits on an email-based recovery flow the platform does not yet have.

## Implementation plan

Each phase ends with its own tests and gates, and nothing merges without approval.

1. **Accept this record**, and revise ADR 0037 with the exceptions §9 describes. *Done 2026-09-13.*
2. **Storage invariants** — `social_identities` with its unique constraints, partial indexes and both triggers, on PostgreSQL and SQLite; tests that prove each trigger with raw statements — including that an unlinked row does not block promotion and a linked one does. `users.password` nullability and the administrator-password `CHECK` belong to the phase that introduces password-less accounts (phase 4), not to this one.
3. **The promotion rule** — `promote()` re-reads the user under `lockForUpdate`, refuses by raising while an identity is linked, before any token is revoked; the controller maps the refusal to `409 PROMOTION_REFUSED_SOCIAL_IDENTITY` and writes `account.promotion_refused` outside the aborted transaction; the contract's documentation names the refusal; `has_linked_social_identity` on the admin user resource. Deliverable before any OAuth exists.
4. **Password-less accounts** — `users.password` nullable and the administrator-password `CHECK`; sign-in against a password-less account fails like a wrong password; every reader of `password` reviewed.
5. **Providers** — the `social_login` Integration capability declaring its required configuration, the provider contract, drivers for the providers the product owner chooses; `auth.social_login_enabled` and `auth.social_redirect_uris`; availability means effective, per ADR 0038; vendor error redaction; no failover.
6. **Sign-in** — state, client PKCE and nonce storage; `GET /providers`, `authorize`, `callback` for linked identities and every refusal in §3; dedicated `user:access` issuance that raises for `admin`; MFA honoured; rate limiting; `auth.social_refused` for the two administrative reasons only; OpenAPI and client regeneration.
7. **Registration, linking and recovery** — social registration behind `auth.registration_enabled`, with a test that the created account has `email_verified_at = NULL` and a test that linking leaves it untouched; `link/authorize`, `link` and re-link, `identities`, unlink with the last-method rule, each recording its action; a recovery flow that proves control of the address at the time of recovery. Social registration is not enabled in production before this phase is complete.
8. **Admin** — provider configuration surfaced in the Authentication area; the user screen explains a refused promotion and that it lifts once every identity is unlinked.

**Status, 2026-09-13.** Phases 2 to 7 are implemented and tested on PostgreSQL and SQLite, uncommitted and awaiting review. Phase 8 is not started: the Admin screens are unchanged, although the regenerated client already carries the new endpoints and fields.

### Implementation notes

What the build settled that this record left open, and where it differs in detail. None of it changes a decision above.

* **Providers.** The only driver shipped is `google`, chosen by the product owner. It is vendor-specific rather than generic OpenID Connect, because `social_identities.provider` is the driver key and a generic driver would make it ambiguous. The seeded row is inactive, not default, with an empty client id. ID tokens are verified locally: RS256 only; issuer, audience (and `azp` when there are several audiences), expiry, issued-at and not-before with 60 seconds of leeway; nonce and subject. Google's signing keys are cached for as long as the response allows (60 s to 24 h), and fetched again at most once a minute for an unknown key id.
* **Required configuration** is enforced where it is written. The generic `PUT /api/v1/admin/integrations/providers/{provider}` refuses to leave a `social_login` provider active without a client id and a client secret: `422 PROVIDER_CONFIGURATION_INCOMPLETE`, with the missing field names and never their values. Partial configuration may be stored while the provider is off. The gateway still checks effectiveness on every use.
* **State** lives in the platform cache's `AUTH` namespace, which fails closed: ten minutes, keyed by a hash of the state and never by the state itself. It is consumed once through an atomic `add`, so a replay or a concurrent second use fails. The platform cache contract gained `add()` for this.
* **Rate limiting.** `authorize`, `callback`, `password/forgot` and `password/reset` are on the auth rate-limit policy. The callback and link also use the login throttle, per provider and source, counting failures. `password/forgot` counts every request, because each can send a message.
* **Recovery (§12)** is `POST /api/v1/auth/password/forgot` and `POST /api/v1/auth/password/reset`, on the framework's password broker: a hashed, expiring, single-use token mailed to the address. A reset revokes every token the account holds and never writes `email_verified_at`. `forgot` answers identically whether or not the address holds an account, and sends after the response so timing does not tell either. The link points at `auth.password_reset_url`, a new setting; while it is empty no link is sent and a warning is logged.
* **Storage.** The partial index `(user_id) WHERE unlinked_at IS NULL` was not added. The partial unique index `(user_id, provider) WHERE unlinked_at IS NULL` has the same leading column and predicate, so it already serves the promotion check, and a plain `user_id` index serves the foreign key. A third trigger makes an identity's `user_id`, `provider` and `provider_subject` immutable, linked or not, so ownership can move only by the rules above and never by an update.
* **Admin resource.** It carries `linked_social_providers` (the driver keys of linked identities) alongside `has_linked_social_identity`, so the Phase 8 screen can say which identity to unlink. It never exposes a subject.
* **Password sign-in to a password-less account** compares against a real hash made once per process with the configured hasher, so it costs the same as a wrong password.
* **The two addresses an operator must supply** are `auth.social_redirect_uris` and `auth.password_reset_url`, both ordinary Authentication settings. Neither has a default, and neither falls back to any domain or to `localhost`. One policy (`ClientUrlPolicy`, in Core) decides whether an address is usable, both when it is written (the setting rules `redirect_uri_list` and `client_page_url`) and every time it is used.
  * **Refused everywhere:** anything but a complete address; a fragment; credentials in the address; a wildcard; for return addresses, a repeat or a scheme other than http, https or a reverse-domain app scheme.
  * **Refused in production:** plain http, and `localhost`, `*.localhost` or any loopback address.
  * **A stored value is judged in the current environment**, so one saved before production, or restored from a backup, is not trusted. An unusable return address is not matched, an unusable reset page sends no link, and in production social registration stays closed until the reset page is usable, because such an account would otherwise have no way back in.
* **What the operator sees.** `GET /api/v1/admin/auth/social-login/setup` (`settings.view`) returns:
  * the return addresses to register as authorized redirect URIs in Google Cloud Console, exactly as configured, with any the environment ignores and why;
  * the reset page;
  * each provider's status, with missing fields named, never their values;
  * the remaining issues, and whether the whole setup is ready.

  The Admin shows it above the Authentication settings, with whether social sign-in is switched on and whether the client id and the client secret are configured (never their values). With nothing configured it contains no address at all.
* **Offered means startable.** `GET /providers` lists a provider only when the switch is on, the provider is effective, and at least one return address is usable in the current environment.
* **The authorized party.** Beyond OpenID Connect's rule for several audiences, an ID token that names an `azp` is refused unless it is this client: the platform redeems only its own authorization codes, so a token authorized for another client did not come from this sign-in.
* **Token issuance** goes through `AuthService::issueToken`, the choke point every access token passes; `issueSocialToken` refuses an administrator first and then delegates, so a social sign-in yields `user:access` and nothing else.
* **The contract** documents each endpoint's refusals: 403, 404, 409, 422, 429 and 502 on the social endpoints; 422 and 429 on recovery; 403 on setup; 409 on promotion; 422 on provider update.
