# AlphaMaster — product capability matrix

Audited 2026-09-10 against the repository and the running platform. Every row is
evidence-backed: a screen existing, an endpoint existing, a setting existing or a green
CI run is **not** treated as proof of anything.

**GREEN** — works end to end: backend capability, contract, generated client,
permission, Admin workflow, persistence, validation, states, tests, and verified in a
browser where a browser is involved.
**YELLOW** — partly there; the row says exactly which link is missing.
**RED** — absent.
**GRAY** — deliberately out of scope, with the record that says so.

---

## Summary

| | Count |
| :--- | ---: |
| GREEN | 17 |
| YELLOW | 9 |
| RED | 9 |
| GRAY | 4 |

The nine RED rows are not evenly weighted. **AI, Firebase, push notifications and
device registration do not exist in any form** — no module, no table, no setting, no
contract, no enum case. They were never built and no ADR describes them. Everything
else RED is a known, recorded gap with an argument attached.

---

## The matrix

### Identity and access

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 1 | Authentication | **GREEN** | `POST /auth/login`, `/logout`, `/me`; five-stage perimeter (`auth:sanctum`, `ability`, `active`, `admin`, `email-verified`); HttpOnly cookie transport (ADR 0042) with a real personal access token, so `tokenCan()` reads real abilities. Sign-in verified in a browser repeatedly. |
| 2 | MFA | **GREEN** | TOTP and SMS OTP; `mfa_methods` with `confirmed_at`, `last_used_slice` replay protection; mandatory for administrators (ADR 0013); enrol/verify/status/disable endpoints; Admin enrolment screen. |
| 3 | CAPTCHA | **GREEN** | reCAPTCHA driver behind the provider manager, v2/v3 settings, site key public and secret encrypted on the provider row, throttled sign-in path, `Http::fake()` tests. |
| 4 | Email verification | **GREEN** | Signed link, `POST /auth/email/verify/send`, administrators refused until verified, address change clears verification. |
| 5 | Phone verification | **YELLOW** | A phone number is stored canonically with a keyed lookup hash and `User::findByPhone()` exists, and SMS-OTP enrolment proves possession of a number. But there is **no phone-verification flow of its own** and no `phone_verified_at`: a number set by an administrator is never confirmed by anyone. Sign-in by phone is unreachable — `LoginRequest` accepts an identifier and `AuthService` resolves it, but nothing marks a number trusted. |
| 6 | OTP | **YELLOW** | Delivery, hashing, expiry (`otp_expires_at`), resend cooldown and single-use replay protection all exist and are tested. **None of it is configurable**: `codeLifetimeSeconds()` and `resendCooldownSeconds()` are constants in `SmsOtpMethod`, and there is no `otp.*` settings group. Attempt limiting applies to sign-in (`security.max_login_attempts`) and not to OTP verification specifically. |
| 27 | Users | **GREEN** | Create, edit, activate/deactivate, promote/demote, role sync; identity-only edits; self-deactivation refused; 16 lifecycle tests; browser-verified end to end. |
| 28 | Roles | **GREEN** | Create, edit, delete, permission assignment, label/identifier separation with a server-derived immutable identifier. |
| 29 | Permissions | **GREEN** | Read-only catalogue grouped by module, role carriers named, orphan permissions called out. Dynamic creation is **intentionally** unsupported — `AdminPermission` is a code enum (ADR 0014) and the screen says so rather than offering CRUD. |
| 30 | Audit trail | **YELLOW** | Configuration, secrets, rollback, archival, backup, mail test, and — new — the full account lifecycle including role assignment. **Role *definitions* are unaudited**: creating a role, changing what it grants, deleting one. ADR 0037's amendment names this as the acknowledged gap. |

### Messaging

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 7 | SMS | **GREEN** | Twilio driver over the HTTP client, provider chain with failover, usage logging, timeout now read from configuration. |
| 8 | Firebase / mobile integration | **RED** | **Zero references in the codebase.** No module, no credentials, no setting, no table, no contract, no capability enum case. Nothing to complete — this is greenfield. |
| 9 | Push notifications | **RED** | `NotificationChannel` has `database`, `mail`, `sms` and a comment saying "WhatsApp and push arrive when the Integration capabilities they need do". No channel, no device table, no transport. |
| 23 | Notifications | **YELLOW** | Templates, per-locale rendering, preferences, the in-app inbox, mark-read, and administrator announcements all work end to end and are browser-verified. |
| 24 | Notification producers | **RED** | `security.alert` and `account.updated` have seeded, active, translated templates and **have never been raised once**. `NotifierContract` lives in the Notification namespace and takes a Notification enum; every module that would produce a notification is forbidden by the architecture rules from importing that namespace. Blocked on decision 1 in `docs/m3-decisions.md`. |
| 25 | Integrations | **GREEN** | Provider list, capability/status/active/default, credential state without ever exposing a secret, usage window, make-default with `PROVIDER_INACTIVE` refusal. |
| 26 | Provider retry / failover | **YELLOW** | Failover works and is ADR 0017's decision: the chain is walked until one succeeds. **Retry against the same provider does not exist** — `operations.provider_retry_attempts` is configured and read by nothing, because retrying a send that may already have arrived is a duplicate-delivery hazard nobody has ruled on. Decision 3. |

### AI

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 10 | AI | **RED** | **Zero references.** No module, no service, no contract, no route. |
| 11 | AI providers | **RED** | `IntegrationCapability` has exactly two cases: `sms`, `captcha`. ADR 0017 names AI as a capability the manager pattern could carry; nothing implements it. |
| 12 | AI settings | **RED** | No `ai` settings group, no credentials, no model, no timeout, no limits. |
| 15 | AI-assisted translation | **RED** | Depends on all three rows above. |

### Localization

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 13 | Languages | **GREEN** | List, create, update, activate/deactivate, set default; deactivating the default refused; the console's own language list comes from the platform (ADR 0015). |
| 14 | Translation management | **YELLOW** | Relational per-locale stores exist and work for the three things that have them — settings, notification templates, role labels. There is **no translation workspace**: no screen that shows what is untranslated across the platform, and no completeness figure in the API. The notifications screen computes template completeness client-side; nothing else does. |
| 16 | Manual language creation | **GREEN** | `POST /admin/languages` with name, native name, code, direction, activation, default and order, and the Admin form that drives it. |
| 37 | RTL / LTR | **GREEN** | Direction comes from the active language, logical properties throughout, swept for horizontal overflow at 375 in both directions across all ten routes. |

### Configuration and platform

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 17 | General settings | **YELLOW** | Sixty-two typed settings with grouping, per-locale values, secrets, history, rollback, backup/restore, optimistic concurrency, and — new — a label and help sentence for every one in both languages. **Nine settings are configured and not consumed** (row 33). |
| 18 | Branding | **YELLOW** | Seven media-typed settings — logos in four combinations, favicon, social image, watermark image — each holding a validated `MediaFile` id, assigned through a picker. `max_image_dimension` is not enforced and the watermark is not applied. |
| 19 | Media | **GREEN** | Upload, scan, storage, delivery, listing with server-side filters, detail, soft delete, purge job, access policy seam, and — new — the upload ceiling read from configuration rather than hard-coded. |
| 20 | Image processing | **RED** | `MediaProcessorContract` exists with one implementation, `GenericFileProcessor`. The container has no `gd`, `imagick` or `ffmpeg`: extensions are `pdo_pgsql, pgsql, pcntl, posix, bcmath, opcache, intl, zip, exif, redis, fileinfo`. |
| 21 | Image variants | **RED** | ADR 0024's extension specifies the whole design — named vocabulary, policy seam, resolution rule — and states none of it is implemented. Blocked on row 20 and on a consumer. Decision 4. |
| 22 | Watermarking | **RED** | Six settings configure it; nothing applies it. Watermarking is a step in deriving an image, so it is blocked behind rows 20 and 21. |
| 31 | Operations | **GREEN** | Audit trail with eight server-side filters, archival with export-verify-remove, configuration export/restore, mail test. |
| 32 | Maintenance mode | **GREEN** | 503 with the platform envelope and a localized message, `admin:access` bypass, fail-open when settings are unreadable, dashboard finding, browser-verified both ways. |
| 33 | Configuration consumption | **YELLOW** | Nine settings exist and nothing reads them: `provider_retry_attempts`, `max_image_dimension`, six `watermark.*`, `registration_enabled`. Each now says so in its help text, which is honest but is not a resting place. |

### Surface

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 34 | Dashboard | **GREEN** | Findings derived from published fields only, checks that could not run reported as not run, destinations gated on the permission the target needs. No invented metrics, no decorative charts. |
| 35 | Admin navigation | **GREEN** | One Settings section over General settings, Users, Roles, Permissions; permission-filtered; direct URLs open their section; drawer at narrow widths. |
| 36 | Theme / visual branding | **RED** | The current identity is **plum/violet** (`--plum-600: #553461` as the primary action) and is rejected. The brand must be `#335C67`. The token system is a sound three-layer structure, so this is a rebalance rather than a rebuild — but it is not started, and `--teal-600: #445f64` currently serves as the *info* accent and sits close enough to the new brand that the two would collide. |
| 38 | Dark / light | **YELLOW** | Both themes exist and every screen was verified in both. Contrast has **not** been re-measured for the new brand, because the new brand does not exist yet. |
| 39 | Responsive behaviour | **GREEN** | Structural switches rather than CSS hiding, verified at 375 and desktop in both directions with no page-level horizontal overflow. |

### Out of scope, by record

| Capability | State | Record |
| :--- | :---: | :--- |
| SEO contracts and metadata store | **GRAY** | ADR 0032: deliberately unphased until a consumer exists whose requirements can test it. |
| Application authorization for regular users | **GRAY** | ADR 0029 item 8: no consumer; must stay conceptually separate from admin RBAC. |
| Public frontend | **GRAY** | Explicitly excluded from this run. |
| Tenancy / workspace scoping | **GRAY** | ADR 0034: single-tenant deployment model. |

---

## Implementation map

Ordered by dependency, then by how much of the product each unblocks.

### Track A — the visual reset (no backend dependency)

1. Replace the plum primitive family with a brand family built from `#335C67`; move
   `info` off teal so semantic colours stay distinct; rebalance the semantic layer
   rather than substituting a hex.
2. Re-verify contrast: light, dark, RTL, LTR, at the sizes actually used (muted text
   runs to 10px, and the existing `--slate-550` half-step exists because of exactly
   that measurement).
3. Rework the shell, dashboard, tables and panels toward an operational control centre:
   density, hierarchy, typography, intentional whitespace. Zero radius stays.

### Track B — decisions already put to the user

4. Notification producer seam (decision 1) → unblocks rows 24, and makes rows 5 and 6
   worth completing.
5. Provider retry semantics (decision 3) → resolves row 26 and one setting in row 33.
6. Image pipeline scheduling (decision 4) → unblocks rows 20, 21, 22 and part of 18.

### Track C — greenfield, needs its own architecture

7. **AI** (rows 10–12, 15). Nothing exists. The natural shape is ADR 0017's manager
   pattern — a capability, a contract, drivers, usage logging, credentials on a
   provider row — which is the pattern SMS and CAPTCHA already follow and which
   ADR 0033's extension explicitly permits ahead of a consumer. Needs an ADR.
8. **Firebase, push, device registration** (rows 8, 9). Nothing exists. A device
   registry is a new table and a new channel; ADR 0019 anticipates the channel and
   ADR 0017 the transport. Needs an ADR.

### Track D — completion of what is already partly built

9. OTP configuration (row 6): an `otp` settings group replacing two constants.
10. Phone verification (row 5): the flow that makes a stored number trusted.
11. Role-definition auditing (row 30).
12. Translation workspace (row 14): completeness in the API, and a screen that shows
    what is untranslated.
13. The remaining unread settings (row 33), each either honoured or removed.
