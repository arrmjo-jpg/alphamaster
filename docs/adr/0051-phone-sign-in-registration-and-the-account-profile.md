# ADR 0051: Phone Sign-In, Public Registration, and the Account Profile

* **Status**: Accepted
* **Date**: 2026-09-13
* **Scope**: user accounts only. Administrative sign-in is unchanged (ADR 0012, ADR 0013).

## Context

The launch scope (set by the product owner on 2026-09-13) makes a phone number and a one-time code the primary way a person signs in, opens public registration, and gives an account a profile: a picture, links to its public presence elsewhere, and where it is.

When this record was written, the platform had none of those. `POST /auth/login` took an identifier and a **password**. Phone verification (M3-D) confirmed a number an account already held. The only self-service way to create an account was social login (ADR 0050). `/auth/me` was read-only.

The pieces this record builds on already existed:
* `OtpPolicy`: length, lifetime, cooldown and attempts, one policy for every code.
* `PhoneNumber`: canonical E.164 numbers, looked up by a keyed hash.
* The SMS dispatcher, the login throttle, the CAPTCHA guard, and the password broker.

## Decision

### 1. Phone sign-in is a one-time code, for user accounts, behind a switch

`auth.phone_sign_in_enabled` is off by default. Turning it on requires a working SMS provider, which is external configuration.

* `POST /api/v1/auth/phone/code` with `{ phone }` sends a code **only** to a number that may use it:
  * an active user account holding that number; or
  * a number that belongs to no account, while `auth.registration_enabled` is on.
* An administrator's number, a suspended account's number, a number inside its resend cooldown, and an unknown number while registration is closed all get **the same 200 answer and no message**. The response never says whether an account exists.
* Every request counts against a throttle keyed on the canonical number and the address, so the endpoint cannot be used to spend the operator's SMS budget. When CAPTCHA is on, it applies here as it does at login.
* A code is stored as a hash, keyed by the number's lookup hash rather than by an account, so the same flow serves sign-in and registration. It obeys `OtpPolicy`. A wrong answer costs an attempt outside any transaction, and reaching the limit discards the code.
* `POST /api/v1/auth/phone/sign-in` with `{ phone, code, name?, preferred_locale? }` answers the code:
  * **Existing user:** the code proves possession of the number, so the number is marked verified if it was not already. Then the account signs in as a password sign-in does: an MFA challenge if a second factor is enrolled, otherwise a `user:access` token.
  * **No account:** a user account is created with that number, already verified, and no password and no email. This requires `auth.registration_enabled` and a `name`. Without a name the answer is `422 REGISTRATION_DETAILS_REQUIRED` and **the code is not spent**, so the holder can resend the request with a name. Only someone holding a valid code for the number learns that it has no account.
* Administrators never sign in this way. An administrator's number never receives a code, and the verify step refuses one anyway.

### 2. Accounts may exist without an email address

`users.email` becomes nullable. A user who registered by phone has no address until they add one. A `CHECK` keeps **administrators required to have an address**, the same shape as ADR 0050's administrator-password rule. Email lookups never match `NULL`, so an account without an address cannot be reached by email sign-in, password recovery or social address matching.

### 3. Public registration by email and password

`POST /api/v1/auth/register` with `{ name, email, password, password_confirmation, phone?, preferred_locale? }`:
* Gated by `auth.registration_enabled`, throttled per address, and behind CAPTCHA when that is on.
* The email is stored in lowercase. The password follows `auth.password_min_length`.
* A number given here is stored **unverified**. Verifying it is phone verification's job.
* The account is always a user. A verification link is sent. `email_verified_at` is written only by that link, never by registration.
* It answers `201` with a `user:access` token, because regular users are not stopped for an unverified address (ADR 0012).
* An address already taken is refused as a validation error. That is an existence disclosure, accepted because the route is throttled and behind CAPTCHA, and because the alternative (silently mailing the existing owner) is a larger surface than this platform needs.

Registration records `account.created` with `source: registration` or `source: phone`. This follows the `source: social` extension ADR 0050 made to ADR 0037. Sign-ins are still not recorded.

### 4. The account's own profile

`/api/v1/profile` acts on the signed-in account and takes no account identifier.

| Method & path | Does |
|---|---|
| `GET /profile` | name, email, phone and their verification, preferred locale, bio, location, avatar URL, links |
| `PATCH /profile` | name, bio, preferred locale, phone, location. A user may also change email; that clears `email_verified_at` |
| `PUT /profile/password` | set or change the password. The current password is required when one exists, and every other token is revoked |
| `PUT /profile/links` | replace the account's profile links (at most 10, ordered) |
| `POST /profile/avatar`, `DELETE /profile/avatar` | set or remove the profile picture |

* **An administrator's email is not changed here.** An administrator is stopped at sign-in until the address is verified, so changing it from the account itself would be a way to lock an administrative account out. Administrators' addresses stay with account management (ADR 0029 item 23 is unchanged).
* **Links are not social identities.** A profile link is a public URL a person chooses to show, with a platform label (website, X, Instagram, and so on). It proves nothing and signs nobody in. Each URL passes `ClientUrlPolicy`'s web-page rule: https in production, no credentials, no fragment.
* **The avatar is media.** The upload goes to the Media module as a public image in the `avatar` collection, attached to the account, and replaces the previous one; the old file is soft-deleted and purged on schedule. Neither User nor Auth may depend on Media, so the profile reads the picture's URL through a Core contract that Media implements, the same inversion `SmsRecipientResolverInterface` uses.

### 5. Location foundations

Nullable columns on `users`: `country_code` (ISO 3166-1 alpha-2, upper case), `region`, `city`, `latitude`, `longitude`, and `location_updated_at`.
* Latitude and longitude are both present or both absent, and in range. PostgreSQL enforces this with `CHECK` constraints, and validation enforces it everywhere.
* **No maps or geocoding provider is chosen.** Coordinates are stored as given. Turning an address into coordinates, or coordinates into a place, needs a provider, which is external configuration and future work.

### 6. What the operator runs, and when

The scheduler container ran `schedule:work` with nothing scheduled. It now runs:

* **Media purge:** daily, removing soft-deleted media older than `operations.media_retention_days` (default 30).
* **Integration usage log pruning:** daily, rows older than `operations.integration_usage_retention_days` (default 90). This closes ADR 0029 item 3.
* **Expired one-time codes:** daily, both phone sign-in codes and phone verification codes.
* **Expired password reset tokens:** daily.

Audit archival stays manual (ADR 0037).

### 7. Cache and CDN, for the operator

* **Cache.** An administrator can see each cache namespace's policy (lifetime, failure mode, shape version, current generation) and invalidate a namespace. Invalidation needs `settings.update` and is audited as `cache.namespace_flushed`. `auth` and `authorization` cannot be invalidated here: `auth` is the source of truth for challenges and states in flight, and `authorization` belongs to the permission package. There is still no raw-key interface (ADR 0035).
* **CDN.** `cdn.enabled` and `cdn.base_url` were read by the media URL resolver but declared nowhere. They become a settings group of their own. The base URL passes `ClientUrlPolicy`'s web-page rule, so production requires https and refuses localhost. **Purging a CDN** needs a vendor driver (ADR 0036), and none is chosen: that remains external configuration and future work.

## Consequences

* A person can register and sign in with nothing but a phone. That makes the SMS provider part of the platform's availability: without one, `auth.phone_sign_in_enabled` must stay off.
* An account may have no password and no email. It can still get back in, because the phone code is itself the way in.
* A regular user's email change clears verification, and so does a phone change, as before.
* Profile links, location and the avatar are data the platform stores and publishes back to its owner. Nothing here publishes them to anyone else. A public profile page is a frontend decision and future work.
* External configuration this depends on: an SMS provider (Twilio credentials), CAPTCHA keys if CAPTCHA is on, a maps or geocoding provider if one is ever wanted, and a CDN vendor for purge.
